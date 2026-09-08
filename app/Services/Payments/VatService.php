<?php

namespace App\Services\Payments;

/**
 * The single place VAT is worked out, so every quote, receipt and invoice agrees.
 *
 * Central Executive Transfers is VAT registered. Two conventions are used:
 *  - PUBLIC / private customers: the price shown is VAT-INCLUSIVE (gross). The
 *    customer never sees a price rise — VAT is simply broken back out of it for
 *    our records and their receipt (fromGross).
 *  - CORPORATE accounts: invoices show a NET figure with 20% VAT added on top so
 *    the business can reclaim it (fromNet).
 *
 * When the company is not VAT registered (config flag off), every breakdown
 * reports zero VAT and net == gross, so nothing changes.
 *
 * @phpstan-type Breakdown array{net: float, vat: float, gross: float, rate: float}
 */
class VatService
{
    /** The VAT rate in force (e.g. 0.20), or 0 when not registered. */
    public function rate(): float
    {
        return $this->registered() ? max(0.0, (float) config('cet.vat_rate', 0.20)) : 0.0;
    }

    /** Is the company VAT registered? Governs whether any VAT is applied at all. */
    public function registered(): bool
    {
        return (bool) config('cet.vat_registered', false);
    }

    /** The VAT registration number for receipts/invoices, or '' when unset. */
    public function number(): string
    {
        return trim((string) config('cet.company.vat_number', ''));
    }

    /**
     * Break a VAT-INCLUSIVE (gross) amount into net + VAT + gross — the public /
     * private-customer convention. £120 gross at 20% → net £100, VAT £20.
     *
     * @return Breakdown
     */
    public function fromGross(float $gross): array
    {
        $gross = round(max(0.0, $gross), 2);
        $rate = $this->rate();
        if ($rate <= 0) {
            return ['net' => $gross, 'vat' => 0.0, 'gross' => $gross, 'rate' => 0.0];
        }
        $net = round($gross / (1 + $rate), 2);

        return ['net' => $net, 'vat' => round($gross - $net, 2), 'gross' => $gross, 'rate' => $rate];
    }

    /**
     * Add VAT to a NET amount — the corporate convention. £100 net at 20% → VAT
     * £20, gross £120.
     *
     * @return Breakdown
     */
    public function fromNet(float $net): array
    {
        $net = round(max(0.0, $net), 2);
        $rate = $this->rate();
        if ($rate <= 0) {
            return ['net' => $net, 'vat' => 0.0, 'gross' => $net, 'rate' => 0.0];
        }
        $vat = round($net * $rate, 2);

        return ['net' => $net, 'vat' => $vat, 'gross' => round($net + $vat, 2), 'rate' => $rate];
    }

    /** VAT rate as a whole-number percentage for labels, e.g. "20". */
    public function ratePercent(): int
    {
        return (int) round($this->rate() * 100);
    }
}
