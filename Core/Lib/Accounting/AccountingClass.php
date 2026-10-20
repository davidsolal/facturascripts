<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2018-2026 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Core\Lib\Accounting;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Asiento;
use FacturaScripts\Dinamic\Model\Partida;
use FacturaScripts\Dinamic\Model\Subcuenta;

/**
 * Base class for creation of accounting processes
 *
 * @author Jose Antonio Cuello Principal <yopli2000@gmail.com>
 * @author Carlos García Gómez           <carlos@facturascripts.com>
 */
abstract class AccountingClass extends AccountingAccounts
{
    /** @var ModelClass */
    protected $document;

    /**
     * Method to launch the accounting process
     *
     * @param ModelClass $model
     */
    public function generate($model)
    {
        $this->document = $model;
        $this->exercise->idempresa = $model->idempresa ?? Tools::settings('default', 'idempresa');
    }

    /**
     * Add a standard line to the accounting entry based on the reported sub-account
     *
     * @param Asiento $accountEntry
     * @param Subcuenta $subaccount
     * @param bool $isDebit
     * @param float $amount
     *
     * @return bool
     */
    protected function addBasicLine($accountEntry, $subaccount, $isDebit, $amount = null): bool
    {
        return $this->getBasicLine($accountEntry, $subaccount, $isDebit, $amount)->save();
    }

    /**
     * Converts a document-currency ("face") amount into the company's default
     * currency. A document issued in a foreign currency must book its value in
     * the default currency, never its face value: an USD 5,000 invoice with an
     * ECB rate of 1.1215 is EUR 4,458.23 of revenue, not EUR 5,000. The document
     * already carries its own conversion of the grand total (totaleuros =
     * total / tasaconv), so the ratio totaleuros/total converts any face amount
     * with the same rate and the same rounding the document itself uses.
     *
     * Documents in the default currency (or without one) pass through
     * unchanged, and a zero total (unpriced documents) cannot produce a ratio
     * so it passes through too: there is nothing to convert.
     */
    protected function toDefaultCurrency(float $face): float
    {
        if ($face === 0.0 || empty($this->document->coddivisa)
            || $this->document->coddivisa === Tools::settings('default', 'coddivisa', '')
            || empty($this->document->total)) {
            return $face;
        }

        return round($face * (float)$this->document->totaleuros / (float)$this->document->total, 2);
    }

    /**
     * Add a group of lines from array of subaccounts/amount.
     *
     * @param Asiento $accountEntry
     * @param array $totals
     * @param bool $isDebit
     * @param Subcuenta $counterpart
     * @param string $accountError
     * @param string $saveError
     *
     * @return bool
     */
    protected function addLinesFromTotals($accountEntry, $totals, $isDebit, $counterpart, $accountError, $saveError): bool
    {
        foreach ($totals as $code => $total) {
            $subaccount = $this->getSubAccount($code);
            if (empty($subaccount->codsubcuenta)) {
                Tools::log()->warning($accountError, [
                    '%subaccount%' => $code,
                    '%exercise%' => $this->exercise->codejercicio
                ]);
                return false;
            }

            $line = $this->getBasicLine($accountEntry, $subaccount, $isDebit, $total);

            if (!empty($counterpart)) {
                $line->setCounterpart($counterpart);
            }

            if (false === $line->save()) {
                Tools::log()->warning($saveError);
                return false;
            }
        }

        return true;
    }

    /**
     * Add a line of taxes to the accounting entry based on the sub-account
     * and values reported
     *
     * @param Asiento $accountEntry
     * @param Subcuenta $subaccount
     * @param Subcuenta $counterpart
     * @param bool $isDebit
     * @param array $values
     *
     * @return bool
     */
    protected function addSurchargeLine($accountEntry, $subaccount, $counterpart, $isDebit, $values): bool
    {
        if (empty($values['totalrecargo'])) {
            return true;
        }

        // add basic data
        $line = $this->getBasicLine($accountEntry, $subaccount, $isDebit, $values['totalrecargo']);

        // counterpart?
        if (!empty($counterpart)) {
            $line->setCounterpart($counterpart);
        }

        // add tax register data. The taxable base is a face-currency amount
        // (neto), so it books in the default currency like the rest of the
        // entry; iva is a percentage and recargo is stored as a percentage on
        // this line, neither is converted.
        $line->baseimponible = $this->toDefaultCurrency((float)$values['neto']);
        $line->iva = 0;
        $line->recargo = (float)$values['recargo'];
        $line->cifnif = $this->document->cifnif;
        $line->codserie = $this->document->codserie;
        $line->documento = $this->document->codigo;
        $line->factura = $this->document->numero;

        // save new line
        return $line->save();
    }

    /**
     * Add a line of taxes to the accounting entry based on the sub-account
     * and values reported
     *
     * @param Asiento $accountEntry
     * @param Subcuenta $subaccount
     * @param Subcuenta $counterpart
     * @param bool $isDebit
     * @param array $values
     *
     * @return bool
     */
    protected function addTaxLine($accountEntry, $subaccount, $counterpart, $isDebit, $values): bool
    {
        // add basic data
        $line = $this->getBasicLine($accountEntry, $subaccount, $isDebit, $values['totaliva']);

        // counterpart?
        if (!empty($counterpart)) {
            $line->setCounterpart($counterpart);
        }

        // add tax register data. The taxable base is a face-currency amount
        // (neto), so it books in the default currency like the rest of the
        // entry; the iva/recargo fields are percentages, never converted.
        $line->baseimponible = $this->toDefaultCurrency((float)$values['neto']);
        $line->iva = (float)$values['iva'];
        $line->recargo = 0;
        $line->cifnif = $this->document->cifnif;
        $line->codserie = $this->document->codserie;
        $line->documento = $this->document->codigo;
        $line->factura = $this->document->numero;

        // save new line
        return $line->save();
    }

    /**
     * Obtain a standard line to the accounting entry based on the reported sub-account
     *
     * @param Asiento $accountEntry
     * @param Subcuenta $subaccount
     * @param bool $isDebit
     * @param float $amount
     *
     * @return Partida
     */
    protected function getBasicLine($accountEntry, $subaccount, $isDebit, $amount = null)
    {
        $line = $accountEntry->getNewLine();
        $line->setAccount($subaccount);

        // Every amount reaching an entry line is face-currency (the document's
        // total, its per-subaccount totals, its taxes), so all of them are
        // converted here - the single choke point every line passes through.
        // amount === null means "the document's grand total", whose conversion
        // is totaleuros itself.
        $total = $amount === null
            ? $this->toDefaultCurrency((float)$this->document->total)
            : $this->toDefaultCurrency($amount);
        if ($isDebit) {
            $line->debe = max($total, 0);
            $line->haber = $total < 0 ? abs($total) : 0;
            return $line;
        }

        $line->debe = $total < 0 ? abs($total) : 0;
        $line->haber = max($total, 0);
        return $line;
    }
}
