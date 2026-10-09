<?php
namespace TapCub;

class TapCub
{
    public function __construct(private string $apiKey, private string $endpoint) {}

    public function track(string $event, array $opts = []): mixed
    {
        return $this->send([[
            'type' => 'track',
            'event' => $event,
            'login_id' => $opts['loginId'] ?? null,
            'device_id' => $opts['deviceId'] ?? null,
            'insert_id' => $opts['insertId'] ?? null,
            'properties' => $opts['properties'] ?? new \stdClass(),
            'context' => array_merge(['platform' => 'server', 'library' => ['name' => 'tapcub-php', 'version' => '0.1.0']], $opts['context'] ?? []),
        ]]);
    }

    public function trackSignup(string $loginId, string $deviceId, array $properties = []): mixed
    {
        return $this->send([['type' => 'track_signup', 'event' => 'sign_up', 'login_id' => $loginId, 'device_id' => $deviceId, 'properties' => $properties]]);
    }

    public function profileSet(string $loginId, array $props): mixed
    {
        return $this->send([['type' => 'profile_set', 'login_id' => $loginId, 'properties' => $props]]);
    }

    public function flush(): void {}
    public function close(): void {}

    private function send(array $events): mixed
    {
        $ch = curl_init(rtrim($this->endpoint, '/') . '/api/v1/track');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey],
            CURLOPT_POSTFIELDS => json_encode(['events' => $events]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return json_decode($body, true);
    }
}
