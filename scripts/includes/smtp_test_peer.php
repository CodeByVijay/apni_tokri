<?php

declare(strict_types=1);

/**
 * Scripted SMTP server on one end of a stream_socket_pair (automated tests only).
 *
 * @param resource $serverEnd
 */
function smtp_test_peer_success_dialog($serverEnd, bool $requireAuth, string $username, string $password): void
{
    stream_set_blocking($serverEnd, true);
    smtp_test_peer_write($serverEnd, '220 mail.test ESMTP ready');

    while (($line = fgets($serverEnd, 8192)) !== false) {
        $cmd = strtoupper(trim($line));
        if ($cmd === '') {
            continue;
        }
        if (strpos($cmd, 'EHLO') === 0 || strpos($cmd, 'HELO') === 0) {
            smtp_test_peer_write($serverEnd, '250-mail.test');
            smtp_test_peer_write($serverEnd, '250 AUTH LOGIN');
            continue;
        }
        if (strpos($cmd, 'STARTTLS') === 0) {
            smtp_test_peer_write($serverEnd, '220 Ready to start TLS');
            continue;
        }
        if (strpos($cmd, 'AUTH LOGIN') === 0) {
            smtp_test_peer_write($serverEnd, '334 ' . base64_encode('Username:'));
            continue;
        }
        if ($requireAuth && $cmd === base64_encode($username)) {
            smtp_test_peer_write($serverEnd, '334 ' . base64_encode('Password:'));
            continue;
        }
        if ($requireAuth && $cmd === base64_encode($password)) {
            smtp_test_peer_write($serverEnd, '235 Authentication successful');
            continue;
        }
        if ($requireAuth && strpos($cmd, 'AUTH') === 0) {
            smtp_test_peer_write($serverEnd, '535 Authentication failed');
            break;
        }
        if (strpos($cmd, 'MAIL FROM:') === 0) {
            smtp_test_peer_write($serverEnd, '250 OK');
            continue;
        }
        if (strpos($cmd, 'RCPT TO:') === 0) {
            smtp_test_peer_write($serverEnd, '250 OK');
            continue;
        }
        if ($cmd === 'DATA') {
            smtp_test_peer_write($serverEnd, '354 End data with <CR><LF>.<CR><LF>');
            while (($dataLine = fgets($serverEnd, 8192)) !== false) {
                if (trim($dataLine) === '.') {
                    break;
                }
            }
            smtp_test_peer_write($serverEnd, '250 Message accepted');
            continue;
        }
        if (strpos($cmd, 'QUIT') === 0) {
            smtp_test_peer_write($serverEnd, '221 Bye');
            break;
        }
    }
    @fclose($serverEnd);
}

/** @param resource $serverEnd */
function smtp_test_peer_auth_fail($serverEnd): void
{
    stream_set_blocking($serverEnd, true);
    smtp_test_peer_write($serverEnd, '220 mail.test ESMTP ready');
    while (($line = fgets($serverEnd, 8192)) !== false) {
        $cmd = strtoupper(trim($line));
        if (strpos($cmd, 'EHLO') === 0) {
            smtp_test_peer_write($serverEnd, '250-mail.test');
            smtp_test_peer_write($serverEnd, '250 AUTH LOGIN');
            continue;
        }
        if (strpos($cmd, 'AUTH LOGIN') === 0) {
            smtp_test_peer_write($serverEnd, '334 Username');
            continue;
        }
        if ($cmd !== '' && $cmd !== 'QUIT') {
            smtp_test_peer_write($serverEnd, '535 Authentication failed');
            break;
        }
    }
    @fclose($serverEnd);
}

/** @param resource $serverEnd */
function smtp_test_peer_reject_rcpt($serverEnd): void
{
    stream_set_blocking($serverEnd, true);
    smtp_test_peer_write($serverEnd, '220 mail.test ESMTP ready');
    while (($line = fgets($serverEnd, 8192)) !== false) {
        $cmd = strtoupper(trim($line));
        if (strpos($cmd, 'EHLO') === 0) {
            smtp_test_peer_write($serverEnd, '250 OK');
            continue;
        }
        if (strpos($cmd, 'MAIL FROM:') === 0) {
            smtp_test_peer_write($serverEnd, '250 OK');
            continue;
        }
        if (strpos($cmd, 'RCPT TO:') === 0) {
            smtp_test_peer_write($serverEnd, '550 Mailbox unavailable');
            break;
        }
    }
    @fclose($serverEnd);
}

/**
 * @param resource $stream
 */
function smtp_test_peer_write($stream, string $line): void
{
    fwrite($stream, $line . "\r\n");
}

/**
 * @return array{0: resource, 1: resource}|null
 */
function smtp_test_peer_create()
{
    $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    }
    if ($pair === false) {
        return null;
    }

    return [$pair[0], $pair[1]];
}

/**
 * @param callable(): array{ok: bool, error?: string} $clientAction
 */
function smtp_test_peer_run_server(callable $serverFn, callable $clientAction): array
{
    $pair = smtp_test_peer_create();
    if ($pair === null) {
        return ['ok' => false, 'error' => 'pair_failed'];
    }
    [$client, $server] = $pair;

    SmtpMailer::setStreamConnector(function () use ($client) {
        return $client;
    });

    if (function_exists('pcntl_fork')) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            SmtpMailer::setStreamConnector(null);

            return ['ok' => false, 'error' => 'fork_failed'];
        }
        if ($pid === 0) {
            $serverFn($server);
            exit(0);
        }
        $result = $clientAction();
        $deadline = microtime(true) + 8.0;
        while (microtime(true) < $deadline) {
            $res = pcntl_waitpid($pid, $status, WNOHANG);
            if ($res === $pid || $res === -1) {
                break;
            }
            usleep(50000);
        }
        if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
            if (function_exists('posix_kill')) {
                posix_kill($pid, SIGTERM);
            }
            pcntl_waitpid($pid, $status);
        }
        Database::disconnect();
        AppSettings::resetCache();
        Install::ensureConnection();
        SmtpMailer::setStreamConnector(null);
        if (is_resource($client)) {
            @fclose($client);
        }

        return $result;
    }

    // Fallback: run server on same process using non-blocking client action is unreliable; skip server side.
    SmtpMailer::setStreamConnector(null);
    @fclose($client);
    @fclose($server);

    return ['ok' => false, 'error' => 'pcntl_unavailable'];
}
