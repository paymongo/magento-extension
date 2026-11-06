<?php

declare(strict_types=1);

namespace PayMongo\Payment\Test\Unit\Gateway;

use PayMongo\Payment\Gateway\PaymongoApiException;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/**
 * Covers reading PayMongo's error out of an API response, so the admin can be shown
 * PayMongo's own sentence instead of the raw response (PYM-31).
 *
 * @package   PayMongo\Payment
 * @author    DevTeam <support@devteam.com>
 * @copyright Copyright (c) 2026 PayMongo Philippines, Inc.
 * @license   MIT
 */
class PaymongoApiExceptionTest extends TestCase
{
    /**
     * PayMongo's error detail and code are kept, and the message is unchanged for existing callers.
     *
     * @return void
     */
    public function testKeepsPayMongosErrorAndTheUsualMessage(): void
    {
        $body = '{"errors":[{"code":"parameter_above_maximum",'
            . '"detail":"Refund amount is greater than the available payout amount."}]}';

        $e = PaymongoApiException::fromResponse($body);

        $this->assertInstanceOf(LocalizedException::class, $e);
        $this->assertSame('Refund amount is greater than the available payout amount.', $e->getDetail());
        $this->assertSame('parameter_above_maximum', $e->getErrorCode());
        $this->assertSame('PayMongo API error: ' . $body, $e->getMessage());
    }

    /**
     * A response in any other shape still gives the exception, without a detail or code.
     *
     * @return void
     */
    public function testAnUnexpectedResponseHasNoDetail(): void
    {
        foreach (['<html>Bad Gateway</html>', '', '{"errors":[]}', '{"errors":[{"detail":""}]}'] as $body) {
            $e = PaymongoApiException::fromResponse($body);

            $this->assertNull($e->getDetail(), $body);
            $this->assertNull($e->getErrorCode(), $body);
            $this->assertSame('PayMongo API error: ' . $body, $e->getMessage());
        }
    }
}
