<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Meta\Tests;

use SilverShop\ConversionTracking\Meta\MetaHash;
use SilverStripe\Dev\SapphireTest;

/**
 * Advanced Matching normalisation + SHA-256 hashing.
 */
class MetaHashTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testHashLowercasesTrimsAndSha256s(): void
    {
        $this->assertSame(hash('sha256', 'buyer@example.com'), MetaHash::hash('  Buyer@Example.COM '));
    }

    public function testHashReturnsNullForEmpty(): void
    {
        $this->assertNull(MetaHash::hash(''));
        $this->assertNull(MetaHash::hash('   '));
        $this->assertNull(MetaHash::hash(null));
    }

    public function testHashPhoneStripsNonDigitsThenHashes(): void
    {
        $this->assertSame(hash('sha256', '31612345678'), MetaHash::hashPhone('+31 6 1234 5678'));
    }

    public function testHashPhoneNullForEmpty(): void
    {
        $this->assertNull(MetaHash::hashPhone(''));
        $this->assertNull(MetaHash::hashPhone('----'));
    }
}
