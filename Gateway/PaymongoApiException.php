<?php

declare(strict_types=1);

namespace PayMongo\Payment\Gateway;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * A PayMongo API request that PayMongo answered with an error.
 *
 * Its message is unchanged ("PayMongo API error: <response body>"), and it is a
 * LocalizedException, so existing callers behave as before. It also keeps the error PayMongo
 * described, so a caller can show the admin PayMongo's own sentence instead of the raw
 * response: the refund does, so a refund PayMongo refuses (for example because the account's
 * available payout is lower than the refund) reads as such (PYM-31).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class PaymongoApiException extends LocalizedException
{
    /** @var string|null */
    private ?string $detail;

    /** @var string|null */
    private ?string $errorCode;

    /**
     * @param Phrase $phrase
     * @param string|null $detail PayMongo's description of the first error, if it gave one
     * @param string|null $errorCode PayMongo's code for the first error, if it gave one
     */
    public function __construct(Phrase $phrase, ?string $detail = null, ?string $errorCode = null)
    {
        parent::__construct($phrase);
        $this->detail = $detail;
        $this->errorCode = $errorCode;
    }

    /**
     * Build the exception from PayMongo's response body.
     *
     * PayMongo answers errors with {"errors":[{"code":"…","detail":"…"}]}. A body in any other
     * shape still gives the exception, without a detail or code.
     *
     * @param string $responseBody
     * @return self
     */
    public static function fromResponse(string $responseBody): self
    {
        $decoded = json_decode($responseBody, true);
        $error = is_array($decoded) ? ($decoded['errors'][0] ?? null) : null;
        $detail = is_array($error) && is_string($error['detail'] ?? null) ? trim($error['detail']) : '';
        $code = is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : '';

        return new self(
            __('PayMongo API error: %1', $responseBody),
            $detail !== '' ? $detail : null,
            $code !== '' ? $code : null
        );
    }

    /**
     * PayMongo's description of the error, e.g. "Refund amount is greater than the available payout amount".
     *
     * @return string|null
     */
    public function getDetail(): ?string
    {
        return $this->detail;
    }

    /**
     * PayMongo's code for the error, e.g. "resource_invalid_state".
     *
     * @return string|null
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
}
