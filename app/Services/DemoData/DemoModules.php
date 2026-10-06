<?php

namespace App\Services\DemoData;

/**
 * Registry of demo modules, in generation order (core first: other modules
 * may reuse its demo customers/products/employees). A class that doesn't
 * exist yet is simply skipped.
 */
class DemoModules
{
    public const CLASSES = [
        Modules\CoreModule::class,
        // People & organisation
        Modules\HrmModule::class,
        Modules\RecruitModule::class,
        Modules\MeetingModule::class,
        Modules\ProjectsModule::class,
        Modules\DocumentsModule::class,
        Modules\MarketingModule::class,
        // Money & sales add-ons
        Modules\AccountingModule::class,
        Modules\RegularExpensesModule::class,
        Modules\EWalletModule::class,
        Modules\CommissionsModule::class,
        Modules\PromotionsModule::class,
        Modules\SubscriptionModule::class,
        Modules\StoreModule::class,
        Modules\BookingsModule::class,
        Modules\ServiceModule::class,
        Modules\PortfolioModule::class,
        // Operations & verticals
        Modules\ManufacturingModule::class,
        Modules\AssetsModule::class,
        Modules\FleetModule::class,
        Modules\HospitalModule::class,
        Modules\SchoolModule::class,
    ];

    /** @return DemoModule[] keyed by module key */
    public static function all(): array
    {
        $out = [];
        foreach (self::CLASSES as $class) {
            if (class_exists($class)) {
                $m = new $class();
                $out[$m->key()] = $m;
            }
        }

        return $out;
    }
}
