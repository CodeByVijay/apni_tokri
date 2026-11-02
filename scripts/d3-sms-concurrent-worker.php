<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

final class D3SlowSimulateSmsGateway implements SmsGatewayAdapter
{
    /** @var int */
    private $delayUs;

    public function __construct(int $delayMicroseconds)
    {
        $this->delayUs = $delayMicroseconds;
    }

    public function send(string $mobile, string $message, string $senderId, ?string $dltTemplateId): array
    {
        if ($this->delayUs > 0) {
            usleep($this->delayUs);
        }
        $id = 'sim_slow_' . bin2hex(random_bytes(6));

        return [
            'ok' => true,
            'message_id' => $id,
            'response' => json_encode(['simulate' => true, 'slow' => true]),
        ];
    }
}

if ($argc < 3) {
    exit(2);
}

$deliveryId = (int) $argv[1];
$eventKey = (string) $argv[2];

if (getenv('D3_SMS_SLOW') === '1') {
    SmsGatewayFactory::setTestAdapter(new D3SlowSimulateSmsGateway(250000));
}

DeliverySmsService::sendDeliveryEvent($deliveryId, $eventKey);
