<?php
declare(strict_types=1);
namespace Komaroff\B2bAssist;
use Bitrix\Main\Application;
use InvalidArgumentException;
use RuntimeException;

/** Tenant-scoped persistence in the actual Bitrix database. No vendor tables are changed. */
final class PortalStore
{
    private $db;
    public function __construct(){ $this->db=Application::getConnection(); }
    private function q(string $value):string{return "'".$this->db->getSqlHelper()->forSql($value)."'";}
    private function data(array $value):string{return $this->q(json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}
    public function install():void{
        $queries=[
          'CREATE TABLE IF NOT EXISTS kl_company (id varchar(32) PRIMARY KEY,name varchar(120) NOT NULL,created_at bigint NOT NULL)',
          'CREATE TABLE IF NOT EXISTS kl_member (user_id int PRIMARY KEY,company_id varchar(32) NOT NULL,role varchar(20) NOT NULL)',
          'CREATE TABLE IF NOT EXISTS kl_quote (id varchar(32) PRIMARY KEY,company_id varchar(32) NOT NULL,revision varchar(64) NOT NULL,payload longtext NOT NULL,created_at bigint NOT NULL,KEY company_date(company_id,created_at))',
          'CREATE TABLE IF NOT EXISTS kl_outbox (quote_id varchar(32) PRIMARY KEY,company_id varchar(32) NOT NULL,envelope longtext NOT NULL,result longtext NULL,state varchar(24) NOT NULL,attempts int NOT NULL DEFAULT 0,next_at bigint NOT NULL,lease_until bigint NOT NULL DEFAULT 0,events longtext NOT NULL,created_at bigint NOT NULL,KEY due(state,next_at),KEY company_date(company_id,created_at))'
        ];
        foreach($queries as $sql)$this->db->queryExecute($sql.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    public function company(string $id,string $name='Тестовая компания'):void{
        $this->db->queryExecute('INSERT IGNORE INTO kl_company (id,name,created_at) VALUES ('.$this->q($id).','.$this->q($name).','.time().')');
    }
    public function member(int $user):?array{
        return $this->db->query('SELECT m.company_id,m.role,c.name FROM kl_member m JOIN kl_company c ON c.id=m.company_id WHERE m.user_id='.$user)->fetch()?:null;
    }
    public function attach(int $user,string $company,string $role):void{
        if(!in_array($role,['buyer','manager'],true))throw new InvalidArgumentException('Роль недопустима.');
        $this->db->queryExecute('INSERT INTO kl_member (user_id,company_id,role) VALUES ('.$user.','.$this->q($company).','.$this->q($role).') ON DUPLICATE KEY UPDATE company_id=VALUES(company_id),role=VALUES(role)');
    }
    public function save(string $company,array $quote):void{
        $this->db->queryExecute('INSERT IGNORE INTO kl_quote (id,company_id,revision,payload,created_at) VALUES ('.$this->q($quote['id']).','.$this->q($company).','.$this->q($quote['revision']).','.$this->data($quote).','.time().')');
    }
    public function quote(string $company,string $id):?array{
        $row=$this->db->query('SELECT payload FROM kl_quote WHERE id='.$this->q($id).' AND company_id='.$this->q($company))->fetch();
        return $row?json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR):null;
    }
    public function history(string $company):array{
        $out=[];$rs=$this->db->query('SELECT payload FROM kl_quote WHERE company_id='.$this->q($company).' ORDER BY created_at DESC,id DESC LIMIT 30');
        while($row=$rs->fetch())$out[]=json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR);return $out;
    }
    public function enqueue(string $company,array $quote,array $envelope):void{
        $id=$this->q($quote['id']);
        $exists=$this->db->query('SELECT quote_id FROM kl_outbox WHERE quote_id='.$id.' AND company_id='.$this->q($company))->fetch();
        if($exists)return;
        $recent=$this->db->query('SELECT COUNT(*) AS n FROM kl_outbox WHERE company_id='.$this->q($company).' AND created_at>'.(time()-3600))->fetch();
        if((int)$recent['n']>=5)throw new InvalidArgumentException('В учебной компании доступно не более пяти заказов в час.');
        $events=[['message'=>'Заказ сохранён в очереди портала.','at'=>gmdate('c')]];
        $this->db->queryExecute('INSERT IGNORE INTO kl_outbox (quote_id,company_id,envelope,state,next_at,events,created_at) VALUES ('.$id.','.$this->q($company).','.$this->data($envelope).",'pending',".time().','.$this->data($events).','.time().')');
    }
    private function view(array $r):array{
        $v=$r['result']?json_decode($r['result'],true,32,JSON_THROW_ON_ERROR):[];
        $statuses=['pending'=>'В очереди отправки','sending'=>'Отправляется в 1С','accepted'=>'Принят в учебной 1С','processing'=>'Комплектуется · статус портала','shipped'=>'Отгружен · учебный статус портала','failed'=>'Отправка отклонена: обновите КП'];
        return ['id'=>isset($v['number'])?'1С-'.$v['number']:'Очередь-'.$r['quote_id'],'quote_id'=>$r['quote_id'],'total_minor'=>$v['total_minor']??json_decode($r['envelope'],true)['total_minor'],
           'status'=>$statuses[$r['state']]??$r['state'],'queue_state'=>$r['state'],'attempts'=>(int)$r['attempts'],'external_id'=>$v['external_id']??null,'idempotent_replay'=>$v['idempotent_replay']??false,'events'=>json_decode($r['events'],true)];
    }
    public function orders(string $company):array{
        $out=[];$rs=$this->db->query('SELECT * FROM kl_outbox WHERE company_id='.$this->q($company).' ORDER BY created_at ASC LIMIT 100');
        while($r=$rs->fetch())$out[]=$this->view($r);return $out;
    }
    public function deliver(string $company,string $id,OneCClient $client,bool $replay=false):?array{
        $where='quote_id='.$this->q($id).' AND company_id='.$this->q($company);
        $this->db->startTransaction();
        try{
          $row=$this->db->query('SELECT * FROM kl_outbox WHERE '.$where.' FOR UPDATE')->fetch();
          if(!$row)throw new InvalidArgumentException('Заказ этой компании не найден.');
          if((int)$row['lease_until']>time()){ $this->db->commitTransaction();return $this->view($row); }
          if(!$replay&&in_array($row['state'],['accepted','processing','shipped','failed'],true)){ $this->db->commitTransaction();return $this->view($row); }
          $this->db->queryExecute("UPDATE kl_outbox SET state='sending',lease_until=".(time()+60).',attempts=attempts+1 WHERE '.$where);
          $this->db->commitTransaction();
        }catch(\Throwable $e){$this->db->rollbackTransaction();throw $e;}
        $events=json_decode($row['events'],true);$attempts=(int)$row['attempts']+1;
        try{
          $accepted=$client->call('/orders',json_decode($row['envelope'],true,32,JSON_THROW_ON_ERROR));
          $newState=in_array($row['state'],['processing','shipped'],true)?$row['state']:'accepted';
          if(!$replay||!($row['result']&&json_decode($row['result'],true)['idempotent_replay']))$events[]=['message'=>$accepted['idempotent_replay']?'1С вернула исходный документ, дубля нет.':'Документ записан в учебную 1С; остаток изменён в базе.','at'=>gmdate('c')];
          $this->db->queryExecute('UPDATE kl_outbox SET state='.$this->q($newState).',result='.$this->data($accepted).',lease_until=0,events='.$this->data(array_slice($events,-30)).' WHERE '.$where);
        }catch(OneCException $e){
          if($row['result']&&in_array($row['state'],['accepted','processing','shipped'],true)){
            $events[]=['message'=>'Повторное подтверждение сейчас недоступно. Исходный документ и статус сохранены.','at'=>gmdate('c')];
            $this->db->queryExecute('UPDATE kl_outbox SET state='.$this->q($row['state']).',lease_until=0,events='.$this->data(array_slice($events,-30)).' WHERE '.$where);
            return $this->view($this->db->query('SELECT * FROM kl_outbox WHERE '.$where)->fetch());
          }
          $retry=$e->getCode()>=500&&$attempts<8;
          $events[]=['message'=>$retry?'1С недоступна; повтор запланирован с тем же ключом.':$e->getMessage(),'at'=>gmdate('c')];
          $this->db->queryExecute('UPDATE kl_outbox SET state='.$this->q($retry?'pending':'failed').',lease_until=0,next_at='.(time()+min(300,10*(2**min(5,$attempts)))).',events='.$this->data(array_slice($events,-30)).' WHERE '.$where);
        }
        $row=$this->db->query('SELECT * FROM kl_outbox WHERE '.$where)->fetch();return $this->view($row);
    }
    public function runQueue(OneCClient $client):int{
        $rs=$this->db->query("SELECT quote_id,company_id FROM kl_outbox WHERE ((state='pending' AND next_at<=".time().") OR (state='sending' AND lease_until<".time().')) ORDER BY created_at LIMIT 5');$n=0;
        while($r=$rs->fetch()){$this->deliver($r['company_id'],$r['quote_id'],$client);$n++;}return $n;
    }
    public function status(string $company,string $id,string $status):void{
        if(!in_array($status,['processing','shipped'],true))throw new InvalidArgumentException('Выберите статус комплектации или отгрузки.');
        $where='quote_id='.$this->q($id).' AND company_id='.$this->q($company);
        $this->db->startTransaction();
        try{
          $r=$this->db->query('SELECT * FROM kl_outbox WHERE '.$where.' FOR UPDATE')->fetch();
          if(!$r||!$r['result'])throw new InvalidArgumentException('Сначала нужен принятый документ 1С.');
          if(!in_array($r['state'],['accepted','processing'],true)||($status==='shipped'&&$r['state']!=='processing'))throw new InvalidArgumentException('Сначала начните комплектацию. Отгрузка завершает учебный заказ.');
          $events=json_decode($r['events'],true);$events[]=['message'=>$status==='processing'?'Менеджер начал комплектацию в портале.':'Менеджер отметил учебную отгрузку в портале; это не документ отгрузки 1С.','at'=>gmdate('c')];
          $this->db->queryExecute('UPDATE kl_outbox SET state='.$this->q($status).',events='.$this->data($events).' WHERE '.$where);$this->db->commitTransaction();
        }catch(\Throwable $e){$this->db->rollbackTransaction();throw $e;}
    }
}
