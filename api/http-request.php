<?php
declare(strict_types=1);

final class EnquiryHttpException extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $error)
    {
        parent::__construct($error);
    }
}

function parse_enquiry_request(array $server, string $raw, int $maxBytes = 16384): array
{
    if (($server['REQUEST_METHOD'] ?? '') !== 'POST') throw new EnquiryHttpException(405, 'method_not_allowed');
    $contentType = strtolower(trim(explode(';', $server['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType !== 'application/json') throw new EnquiryHttpException(415, 'unsupported_media_type');
    $declaredLength = filter_var($server['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
    if (($declaredLength !== false && $declaredLength > $maxBytes) || strlen($raw) > $maxBytes) {
        throw new EnquiryHttpException(413, 'payload_too_large');
    }
    try {
        $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($input) || array_is_list($input)) throw new JsonException('Expected an object.');
        return $input;
    } catch (JsonException) {
        throw new EnquiryHttpException(400, 'malformed_json');
    }
}
