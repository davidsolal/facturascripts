<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2026 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Test\Core\Lib;

use FacturaScripts\Core\Lib\Accounting\InvoiceToAccounting;
use FacturaScripts\Dinamic\Model\Asiento;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\Subcuenta;
use FacturaScripts\Core\Tools;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Foreign-currency documents must book their value in the company's default
 * currency, never their face value: an USD 5,000 invoice is EUR 4,458.23 of
 * revenue when totaleuros says so, not EUR 5,000.
 */
final class AccountingCurrencyConversionTest extends TestCase
{
    /** @var string */
    private $previousDefaultCurrency;

    protected function setUp(): void
    {
        $this->previousDefaultCurrency = Tools::settings('default', 'coddivisa', '');
        Tools::settingsSet('default', 'coddivisa', 'EUR');
    }

    protected function tearDown(): void
    {
        Tools::settingsSet('default', 'coddivisa', $this->previousDefaultCurrency);
    }

    private function accountingWithDocument(FacturaCliente $invoice): InvoiceToAccounting
    {
        $accounting = new InvoiceToAccounting();
        $property = new ReflectionProperty(InvoiceToAccounting::class, 'document');
        $property->setValue($accounting, $invoice);
        return $accounting;
    }

    private function usdInvoice(): FacturaCliente
    {
        $invoice = new FacturaCliente();
        $invoice->coddivisa = 'USD';
        $invoice->total = 5000.0;
        $invoice->totaleuros = 4458.23;
        return $invoice;
    }

    public function testGrandTotalBooksAsEuros(): void
    {
        // amount === null means "the document's grand total": the line must be
        // the EUR total, not the USD face value.
        $method = new ReflectionMethod(InvoiceToAccounting::class, 'getBasicLine');
        $line = $method->invoke(
            $this->accountingWithDocument($this->usdInvoice()),
            new Asiento(),
            new Subcuenta(),
            true,
            null
        );
        $this->assertEquals(4458.23, $line->debe, 'foreign-currency-line-books-face-value');
    }

    public function testPartialAmountConvertsWithDocumentRatio(): void
    {
        // a tax or goods line of USD 2,500 on the same document converts with
        // the document's own ratio (4458.23 / 5000), not with a fresh one
        $accounting = $this->accountingWithDocument($this->usdInvoice());
        $method = new ReflectionMethod(InvoiceToAccounting::class, 'toDefaultCurrency');
        $this->assertEquals(2229.12, $method->invoke($accounting, 2500.0), 'partial-amount-ratio');
    }

    public function testDefaultCurrencyPassesThrough(): void
    {
        $invoice = new FacturaCliente();
        $invoice->coddivisa = 'EUR';
        $invoice->total = 5000.0;
        $invoice->totaleuros = 5000.0;

        $method = new ReflectionMethod(InvoiceToAccounting::class, 'getBasicLine');
        $line = $method->invoke(
            $this->accountingWithDocument($invoice),
            new Asiento(),
            new Subcuenta(),
            true,
            null
        );
        $this->assertEquals(5000.0, $line->debe, 'default-currency-changed');
    }

    public function testUnpricedDocumentPassesThrough(): void
    {
        // total 0: no ratio exists, nothing to convert - the amount must come
        // through untouched instead of dividing by zero
        $invoice = $this->usdInvoice();
        $invoice->total = 0.0;
        $invoice->totaleuros = 0.0;

        $accounting = $this->accountingWithDocument($invoice);
        $method = new ReflectionMethod(InvoiceToAccounting::class, 'toDefaultCurrency');
        $this->assertEquals(123.45, $method->invoke($accounting, 123.45), 'zero-total-converted');
    }

    public function testTaxBaseConverts(): void
    {
        // the taxable base (neto) is a face amount and books in EUR too
        $accounting = $this->accountingWithDocument($this->usdInvoice());
        $method = new ReflectionMethod(InvoiceToAccounting::class, 'toDefaultCurrency');
        $this->assertEquals(4458.23, $method->invoke($accounting, 5000.0), 'tax-base-conversion');
    }
}