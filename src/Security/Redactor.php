<?php

declare(strict_types=1);

namespace Infocyph\FoundationMcp\Security;

final class Redactor
{
    private const string SECRET_NAME = 'password|secret|token|api[_-]?key|private[_-]?key|authorization|cookie|credential|dsn';

    public function redact(string $content): string
    {
        $content = preg_replace(
            '~-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?-----END [A-Z0-9 ]*PRIVATE KEY-----~s',
            '[REDACTED PRIVATE KEY]',
            $content,
        ) ?? $content;

        $content = preg_replace(
            '#(?i)\bBearer\s+[A-Za-z0-9._~+/=-]{12,}#',
            'Bearer [REDACTED]',
            $content,
        ) ?? $content;

        $content = preg_replace(
            '~(?im)(\b(?:authorization|cookie)\b\s*:\s*)[^\r\n]+~',
            '$1[REDACTED]',
            $content,
        ) ?? $content;

        $content = preg_replace_callback(
            '~(?im)(\b(?:' . self::SECRET_NAME . ')\b\s*(?:=>|=|:)\s*)(["\'])([^"\']*)(["\'])~',
            static fn(array $match): string => $match[1] . $match[2] . '[REDACTED]' . $match[4],
            $content,
        ) ?? $content;

        $content = preg_replace(
            '~(?im)(\b(?:' . self::SECRET_NAME . ')\b\s*(?:=>|=|:)\s*)(?!["\'])([^\s,;#]+)~',
            '$1[REDACTED]',
            $content,
        ) ?? $content;

        return preg_replace(
            '~\b(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|sk-[A-Za-z0-9_-]{20,}|xox[baprs]-[A-Za-z0-9-]{10,}|AKIA[0-9A-Z]{16})\b~',
            '[REDACTED TOKEN]',
            $content,
        ) ?? $content;
    }
}
