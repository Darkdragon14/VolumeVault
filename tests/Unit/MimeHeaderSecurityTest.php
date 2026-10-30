<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Mime\Header\ParameterizedHeader;

class MimeHeaderSecurityTest extends TestCase
{
    #[DataProvider('invalidParameterNames')]
    public function test_mime_parameter_names_cannot_inject_headers(string $parameter): void
    {
        $this->expectException(RfcComplianceException::class);

        new ParameterizedHeader('Content-Disposition', 'attachment', [$parameter => 'backup.zip']);
    }

    public function test_valid_mime_parameters_still_serialize(): void
    {
        $header = new ParameterizedHeader('Content-Disposition', 'attachment', ['filename' => 'backup.zip']);

        $this->assertSame('attachment; filename=backup.zip', $header->getBodyAsString());
    }

    /** @return array<string, array{string}> */
    public static function invalidParameterNames(): array
    {
        return [
            'injected header' => ["filename\r\nX-Injected: value"],
            'trailing newline' => ["filename\n"],
            'whitespace' => ['file name'],
            'separator' => ['file;name'],
        ];
    }
}
