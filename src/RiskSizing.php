<?php
declare(strict_types=1);
namespace ChartEntryLab;

/** Fractional units for research. Risk is a modeled budget, not a guaranteed maximum. */
final class RiskSizing
{
    public static function calculate(float $entry,float $stop,float $budget=100.0,float $capital=10000.0,
        float $feeBps=10.0,float $slippageBps=5.0): ?array
    {
        foreach([$entry,$stop,$budget,$capital,$feeBps,$slippageBps] as $v) {
            if(!is_finite($v)) throw new \InvalidArgumentException('Finite inputs required');
        }
        if($entry<=0 || $stop<=0 || $entry<=$stop || $budget<=0 || $capital<=0 ||
            $feeBps<0 || $feeBps>=10000 || $slippageBps<0 || $slippageBps>=10000) {
            throw new \InvalidArgumentException('Invalid risk inputs');
        }
        $unitCost=$entry*(1+$feeBps/10000);
        $unitRisk=$unitCost-$stop*(1-$slippageBps/10000)*(1-$feeBps/10000);
        $quantity=$budget/$unitRisk;
        $notional=$quantity*$unitCost;
        if($notional>$capital+1e-8) return null;
        return ['quantity'=>$quantity,'modeled_risk'=>$quantity*$unitRisk,
            'notional_including_entry_fee'=>$notional,'risk_budget'=>$budget,
            'unit_risk'=>$unitRisk,'capital_limit'=>$capital];
    }
}
