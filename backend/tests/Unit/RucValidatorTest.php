<?php

namespace Tests\Unit;

use App\Domain\Customers\RucValidator;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class RucValidatorTest extends TestCase
{
    // Fixed public examples, source documented in docs/api/clientes.md.
    #[TestWith(['20131312955'])]
    #[TestWith(['20600044975'])]
    #[TestWith(['20555195444'])]
    #[TestWith(['20100070970'])]
    #[TestWith(['20434327611'])]
    public function test_known_ruc_examples(string $ruc): void
    {
        $this->assertTrue(RucValidator::isValid($ruc));
    }

    #[TestWith(['20131312954'])]
    #[TestWith(['30131312955'])]
    #[TestWith(['2013131295'])]
    #[TestWith(['201313129555'])]
    #[TestWith(['2013131295A'])]
    #[TestWith([' 20131312955'])]
    #[TestWith(['00000000000'])]
    public function test_invalid_documents(string $ruc): void
    {
        $this->assertFalse(RucValidator::isValid($ruc));
    }
}
