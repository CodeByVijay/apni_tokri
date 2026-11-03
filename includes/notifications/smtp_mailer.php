<?php

declare(strict_types=1);

/**
 * Native PHP SMTP client (no Composer). Sends only when the server accepts DATA.
 */
final class SmtpMailer
{
    /** @var array<string, mixed> */
    private $config;

    /** @var callable|null */
    private static $streamConnector;

    /** @var resource|null */
    private $stream;

    /** @var list<array{filename: string, mime: string, content: string}> */
    private $attachments = [];

    /** @param array<string, mixed>|null $config */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? SmtpSettingsService::runtimeConfig();
    }

    /** @param callable(array<string, mixed>): resource|null $connector */
    public static function setStreamConnector(?callable $connector): void
    {
        self::$streamConnector = $connector;
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function send(string $to, string $subject, string $textBody, ?string $htmlBody = null, array $attachments = []): array
    {
        $this->attachments = $attachments;
        $ready = SmtpSettingsService::readinessError($this->config);
        if ($ready !== null) {
            return ['ok' => false, 'error' => $ready];
        }

        if (SmtpSettingsService::containsHeaderInjection($to)
            || SmtpSettingsService::containsHeaderInjection($subject)) {
            return ['ok' => false, 'error' => 'invalid_headers'];
        }

        $fromEmail = trim((string) ($this->config['from_email'] ?? ''));
        $fromName = SmtpSettingsService::sanitizeDisplayName((string) ($this->config['from_name'] ?? ''));
        if ($fromEmail === '' || !Validation::email($fromEmail)) {
            return ['ok' => false, 'error' => 'Sender email is not configured.'];
        }

        $textBody = str_replace("\0", '', $textBody);
        if ($htmlBody !== null) {
            $htmlBody = str_replace("\0", '', $htmlBody);
        }

        try {
            $this->connect();
            $this->expectCode(220);
            $this->ehlo();

            $encryption = (string) ($this->config['encryption'] ?? SmtpSettingsService::ENCRYPTION_NONE);
            if ($encryption === SmtpSettingsService::ENCRYPTION_STARTTLS) {
                $this->sendLine('STARTTLS');
                $this->expectCode(220);
                if (!stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return ['ok' => false, 'error' => 'starttls_failed'];
                }
                $this->ehlo();
            }

            if (!empty($this->config['auth_enabled'])) {
                $auth = $this->authenticateLogin(
                    (string) ($this->config['username'] ?? ''),
                    (string) ($this->config['password'] ?? '')
                );
                if (!$auth['ok']) {
                    $this->close();

                    return $auth;
                }
            }

            $this->sendLine('MAIL FROM:<' . $fromEmail . '>');
            $this->expectCode(250);

            $this->sendLine('RCPT TO:<' . $to . '>');
            $this->expectCode(250);

            $this->sendLine('DATA');
            $this->expectCode(354);

            $message = $this->buildMessage($fromEmail, $fromName, $to, $subject, $textBody, $htmlBody);
            $this->writeRaw($message);
            $this->sendLine('.');
            $this->expectCode(250);

            $this->sendLine('QUIT');
            $this->close();

            return ['ok' => true];
        } catch (SmtpProtocolException $e) {
            $this->close();

            return ['ok' => false, 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            $this->close();
            error_log('SmtpMailer: send failed');

            return ['ok' => false, 'error' => 'connection_failed'];
        }
    }

    public static function publicErrorMessage(string $internalCode): string
    {
        $code = strtolower(trim($internalCode));
        if ($code === 'smtp is disabled.' || $code === 'smtp is disabled') {
            return 'SMTP is disabled. Enable it in settings before sending.';
        }
        if (strpos($code, 'not configured') !== false) {
            return 'SMTP is not fully configured. Check host, sender, and authentication settings.';
        }
        if ($code === 'auth_failed' || strpos($code, 'authentication') !== false) {
            return 'SMTP authentication failed. Check username and password.';
        }
        if ($code === 'connection_failed' || $code === 'connect_failed' || $code === 'starttls_failed') {
            return 'Could not connect to the mail server. Check host, port, and encryption.';
        }
        if (strpos($code, 'smtp_rejected_') === 0 || $code === 'data_rejected') {
            return 'The mail server rejected the message.';
        }
        if ($code === 'invalid_headers') {
            return 'Invalid email headers.';
        }

        return 'Could not send the test email. Check SMTP settings and try again.';
    }

    private function connect(): void
    {
        if (self::$streamConnector !== null) {
            $stream = (self::$streamConnector)($this->config);
            if (!is_resource($stream)) {
                throw new SmtpProtocolException('connect_failed');
            }
            $this->stream = $stream;
            stream_set_timeout($this->stream, (int) ($this->config['timeout_seconds'] ?? 15));

            return;
        }

        $host = (string) ($this->config['host'] ?? '');
        $port = (int) ($this->config['port'] ?? 587);
        $timeout = (int) ($this->config['timeout_seconds'] ?? 15);
        $encryption = (string) ($this->config['encryption'] ?? SmtpSettingsService::ENCRYPTION_NONE);

        $remote = ($encryption === SmtpSettingsService::ENCRYPTION_SSL ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($stream)) {
            throw new SmtpProtocolException('connect_failed');
        }
        $this->stream = $stream;
        stream_set_timeout($this->stream, $timeout);
    }

    private function ehlo(): void
    {
        $name = 'localhost';
        $this->sendLine('EHLO ' . $name);
        $this->expectCode(250);
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    private function authenticateLogin(string $username, string $password): array
    {
        if ($username === '' || $password === '') {
            return ['ok' => false, 'error' => 'auth_failed'];
        }
        $this->sendLine('AUTH LOGIN');
        try {
            $this->expectCode(334);
        } catch (SmtpProtocolException $e) {
            return ['ok' => false, 'error' => 'auth_failed'];
        }
        $this->sendLine(base64_encode($username));
        try {
            $this->expectCode(334);
        } catch (SmtpProtocolException $e) {
            return ['ok' => false, 'error' => 'auth_failed'];
        }
        $this->sendLine(base64_encode($password));
        try {
            $this->expectCode(235);
        } catch (SmtpProtocolException $e) {
            return ['ok' => false, 'error' => 'auth_failed'];
        }

        return ['ok' => true];
    }

    private function buildMessage(
        string $fromEmail,
        string $fromName,
        string $to,
        string $subject,
        string $textBody,
        ?string $htmlBody
    ): string {
        $fromHeader = $fromName !== ''
            ? self::encodeHeaderAddress($fromName, $fromEmail)
            : $fromEmail;
        $date = gmdate('D, d M Y H:i:s') . ' +0000';
        $messageId = '<' . bin2hex(random_bytes(16)) . '@apnitokri.local>';

        $headers = [
            'Date: ' . $date,
            'From: ' . $fromHeader,
            'To: ' . $to,
            'Subject: ' . self::encodeHeaderValue($subject),
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
        ];

        $altBody = null;
        if ($htmlBody !== null && $htmlBody !== '') {
            $boundary = 'at_' . bin2hex(random_bytes(12));
            $altBody = "--{$boundary}\r\n";
            $altBody .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $altBody .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $altBody .= self::dotStuff($textBody) . "\r\n";
            $altBody .= "--{$boundary}\r\n";
            $altBody .= "Content-Type: text/html; charset=UTF-8\r\n";
            $altBody .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $altBody .= self::dotStuff($htmlBody) . "\r\n";
            $altBody .= "--{$boundary}--\r\n";
        }
        if ($this->attachments !== []) {
            $mixed = 'atm_' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mixed . '"';
            $body = "--{$mixed}\r\n";
            if ($altBody !== null) {
                $body .= 'Content-Type: multipart/alternative; boundary="' . $boundary . "\"\r\n\r\n" . $altBody;
            } else {
                $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . self::dotStuff($textBody) . "\r\n";
            }
            foreach ($this->attachments as $att) {
                $fn = preg_replace('/[^A-Za-z0-9._\-]+/', '_', (string) $att['filename']) ?: 'attachment';
                $body .= "--{$mixed}\r\n";
                $body .= 'Content-Type: ' . $att['mime'] . '; name="' . $fn . "\"\r\n";
                $body .= "Content-Transfer-Encoding: base64\r\n";
                $body .= 'Content-Disposition: attachment; filename="' . $fn . "\"\r\n\r\n";
                $body .= chunk_split(base64_encode((string) $att['content']), 76, "\r\n");
            }
            $body .= "--{$mixed}--\r\n";
        } elseif ($altBody !== null) {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body = $altBody;
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $body = self::dotStuff($textBody) . "\r\n";
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private static function encodeHeaderValue(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }

        return $value;
    }

    private static function encodeHeaderAddress(string $name, string $email): string
    {
        return self::encodeHeaderValue($name) . ' <' . $email . '>';
    }

    private static function dotStuff(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = explode("\n", $body);
        foreach ($lines as $i => $line) {
            if (isset($line[0]) && $line[0] === '.') {
                $lines[$i] = '.' . $line;
            }
        }

        return implode("\r\n", $lines);
    }

    private function sendLine(string $line): void
    {
        $this->writeRaw($line . "\r\n");
    }

    private function writeRaw(string $data): void
    {
        if (!is_resource($this->stream)) {
            throw new SmtpProtocolException('connect_failed');
        }
        $len = strlen($data);
        $offset = 0;
        while ($offset < $len) {
            $written = fwrite($this->stream, substr($data, $offset));
            if ($written === false) {
                throw new SmtpProtocolException('connection_failed');
            }
            $offset += $written;
        }
    }

    private function expectCode(int $expected): void
    {
        $response = $this->readResponse();
        $code = (int) substr($response, 0, 3);
        if ($code !== $expected) {
            throw new SmtpProtocolException('smtp_rejected_' . $code);
        }
    }

    private function readResponse(): string
    {
        if (!is_resource($this->stream)) {
            throw new SmtpProtocolException('connection_failed');
        }
        $data = '';
        while (($line = fgets($this->stream, 8192)) !== false) {
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        if ($data === '') {
            throw new SmtpProtocolException('connection_failed');
        }

        return $data;
    }

    private function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->stream = null;
    }
}

final class SmtpProtocolException extends RuntimeException
{
}
