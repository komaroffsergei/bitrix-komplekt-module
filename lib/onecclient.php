<?php
declare(strict_types=1);
namespace Komaroff\B2bAssist;

use RuntimeException;

final class OneCException extends RuntimeException {}

/** Local educational adapter. The transport secret stays on the PHP server. */
final class OneCClient
{
    public function __construct(private string $configPath) {}

    public function call(string $path, ?array $payload = null): array
    {
        if (!is_file($this->configPath)) {
            throw new OneCException('Локальный адаптер 1С не настроен.', 503);
        }
        $config = json_decode(file_get_contents($this->configPath), true, 16, JSON_THROW_ON_ERROR);
        if (($config['url'] ?? '') !== 'http://127.0.0.1:18781') {
            throw new OneCException('Проверьте локальный адрес адаптера 1С.', 503);
        }
        $options = ['method' => $payload === null ? 'GET' : 'POST', 'timeout' => 15,
            'ignore_errors' => true, 'follow_location' => 0,
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . $config['token'] . "\r\n"];
        if ($payload !== null) {
            $options['content'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $body = @file_get_contents($config['url'] . $path, false, stream_context_create(['http' => $options]));
        if ($body === false) {
            throw new OneCException('Нет связи с учебной 1С. Запустите локальный адаптер и повторите отправку.', 503);
        }
        $status = 503;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('~^HTTP/\S+ (\d+)~', $header, $match)) { $status = (int)$match[1]; }
        }
        $result = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if ($status !== 200) {
            $message = 'Учебная 1С отклонила запрос. Проверьте состав и повторите подбор.';
            if ($status === 409 && preg_match('/CONFLICT: ([^\r\n]+)/u', $result['error'] ?? '', $match)) {
                $message = '1С: ' . $match[1];
            }
            if ($status >= 500) { $message = 'Учебная 1С недоступна. Перезапустите локальный адаптер и повторите отправку.'; }
            throw new OneCException($message, in_array($status, [409, 422, 503], true) ? $status : 503);
        }
        return $result;
    }
}
