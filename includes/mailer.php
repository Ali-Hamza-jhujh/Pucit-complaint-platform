<?php
/**
 * includes/mailer.php
 * Native SMTP and System Email Dispatcher
 *
 * Dispatches transactional emails via authenticated SMTP (e.g. Gmail TLS/SSL)
 * with graceful fallback to file-based email logging for offline/local development.
 */

if (!defined('APP_NAME')) {
    require_once __DIR__ . '/../config.php';
}

function send_system_email(string $to, string $subject, string $body, string $reply_to = ''): bool {
    $smtp_enabled = defined('SMTP_ENABLED') && SMTP_ENABLED;
    $smtp_pass    = defined('SMTP_PASS') ? SMTP_PASS : '';
    
    // Fallback: If SMTP is not configured or password is empty, write to dev log
    if (!$smtp_enabled || empty($smtp_pass)) {
        return log_email_locally($to, $subject, $body, $reply_to, "Local/Dev mode (SMTP disabled or empty password)");
    }

    $host     = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
    $port     = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
    $user     = defined('SMTP_USER') ? SMTP_USER : SUPPORT_EMAIL;
    $from_name= defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : APP_NAME;

    try {
        $timeout = 10;
        $prefix  = ($port === 465) ? 'ssl://' : '';
        $socket  = @fsockopen($prefix . $host, $port, $errno, $errstr, $timeout);
        
        if (!$socket) {
            log_email_locally($to, $subject, $body, $reply_to, "Socket connect failed: $errstr ($errno)");
            return false;
        }

        $read = function() use ($socket) {
            $data = '';
            while ($str = fgets($socket, 515)) {
                $data .= $str;
                if (substr($str, 3, 1) === ' ') break;
            }
            return $data;
        };

        $write = function(string $cmd) use ($socket) {
            fputs($socket, $cmd . "\r\n");
        };

        $res = $read();
        if (substr($res, 0, 3) !== '220') {
            fclose($socket);
            return false;
        }

        $write("EHLO " . gethostname());
        $read();

        // STARTTLS on port 587
        if ($port === 587) {
            $write("STARTTLS");
            $tls_res = $read();
            if (substr($tls_res, 0, 3) !== '220') {
                fclose($socket);
                return false;
            }
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                fclose($socket);
                return false;
            }
            $write("EHLO " . gethostname());
            $read();
        }

        // Authenticate
        $write("AUTH LOGIN");
        $read();
        $write(base64_encode($user));
        $read();
        $write(base64_encode($smtp_pass));
        $auth_res = $read();
        if (substr($auth_res, 0, 3) !== '235') {
            fclose($socket);
            log_email_locally($to, $subject, $body, $reply_to, "SMTP Authentication Failed (check App Password)");
            return false;
        }

        // Envelope
        $write("MAIL FROM: <{$user}>");
        $read();
        $write("RCPT TO: <{$to}>");
        $read();
        $write("DATA");
        $read();

        // Headers + Body
        $headers = "From: =?UTF-8?B?" . base64_encode($from_name) . "?= <{$user}>\r\n"
                 . "To: <{$to}>\r\n"
                 . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
                 . "Date: " . date('r') . "\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: 8bit\r\n";
        if (!empty($reply_to)) {
            $headers .= "Reply-To: <{$reply_to}>\r\n";
        }
        $headers .= "X-Mailer: PUCIT-Portal-Mailer\r\n\r\n";

        $write($headers . $body . "\r\n.");
        $read();

        $write("QUIT");
        $read();
        fclose($socket);

        log_email_locally($to, $subject, $body, $reply_to, "DISPATCHED VIA GMAIL SMTP (250 OK)");

        return true;
    } catch (\Throwable $e) {
        log_email_locally($to, $subject, $body, $reply_to, "Exception: " . $e->getMessage());
        return false;
    }
}

function log_email_locally(string $to, string $subject, string $body, string $reply_to = '', string $note = ''): bool {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $file = $dir . '/emails.log';
    $entry = "=================================================================\n"
           . "TIMESTAMP : " . date('Y-m-d H:i:s') . "\n"
           . "TO        : {$to}\n"
           . "SUBJECT   : {$subject}\n"
           . ($reply_to ? "REPLY-TO  : {$reply_to}\n" : "")
           . ($note ? "NOTE      : {$note}\n" : "")
           . "-----------------------------------------------------------------\n"
           . $body . "\n\n";
    @file_put_contents($file, $entry, FILE_APPEND);
    return true;
}
