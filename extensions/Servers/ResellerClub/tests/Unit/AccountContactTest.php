<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Paymenter\Extensions\Servers\ResellerClub\Core\AccountContact;

class AccountContactTest extends TestCase
{
    public function test_uk_national_number_gets_44_and_drops_trunk_zero(): void
    {
        $this->assertSame(['44', '7700900123'], AccountContact::splitPhone('07700 900123', 'GB'));
    }

    public function test_international_number_is_split_on_known_code(): void
    {
        $this->assertSame(['44', '7700900123'], AccountContact::splitPhone('+44 7700 900123', 'GB'));
        $this->assertSame(['353', '871234567'], AccountContact::splitPhone('00353 87 123 4567', 'GB'));
    }

    public function test_unknown_country_without_plus_is_not_guessed(): void
    {
        $this->assertSame([null, null], AccountContact::splitPhone('0123456', 'ZZ'));
        $this->assertSame([null, null], AccountContact::splitPhone('', 'GB'));
    }

    public function test_full_account_builds_complete_contact(): void
    {
        $r = AccountContact::build('Hannah Example', 'h@example.com', [
            'address' => '1 High St', 'address2' => 'Flat 2', 'city' => 'Bury', 'zip' => 'BL0 9AA', 'country' => 'gb', 'phone' => '07700 900123',
            'company_name' => 'Example Ltd',
        ]);
        $this->assertSame([], $r['missing']);
        $this->assertSame('1 High St, Flat 2', $r['contact']['address']);
        $this->assertSame('GB', $r['contact']['country']);
        $this->assertSame('44', $r['contact']['phone_cc']);
        $this->assertSame('Example Ltd', $r['contact']['company']);
    }

    public function test_migrated_account_with_only_address2_still_has_an_address_line(): void
    {
        // Seen on live data: WHMCS import put the house number in address2 and left address empty.
        $r = AccountContact::build('A B', 'a@b.c', ['address2' => '14', 'city' => 'Nottingham', 'country' => 'GB', 'zip' => 'NG2 1PJ', 'phone' => '07700900123']);
        $this->assertSame('14', $r['contact']['address']);
        $this->assertSame([], $r['missing']);
    }

    public function test_missing_fields_are_reported(): void
    {
        $r = AccountContact::build('A B', 'a@b.c', ['city' => 'Nottingham', 'country' => 'GB']);
        $this->assertSame(['address', 'zip', 'phone_cc', 'phone'], $r['missing']);
    }
}
