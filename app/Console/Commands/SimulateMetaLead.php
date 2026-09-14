<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\MetaLeadService;
use Illuminate\Console\Command;

/**
 * Creates a lead exactly as a Meta Instant Form would, without needing a live
 * Meta app. Lets the CRM side be exercised before App Review is granted.
 */
class SimulateMetaLead extends Command
{
    protected $signature = 'meta:simulate-lead
        {--name=Hina Raza}
        {--email=hina@example.com}
        {--phone=+923001234567}
        {--question=which_property_type}
        {--answer=Villa}';

    protected $description = 'Import a fake Meta Instant Form lead for testing';

    public function handle(MetaLeadService $meta): int
    {
        $payload = [
            'id' => 'sim-'.now()->format('YmdHis').'-'.random_int(100, 999),
            'created_time' => now()->toIso8601String(),
            'form_id' => 'simulated-form',
            'field_data' => [
                ['name' => 'full_name', 'values' => [$this->option('name')]],
                ['name' => 'email', 'values' => [$this->option('email')]],
                ['name' => 'phone_number', 'values' => [$this->option('phone')]],
                ['name' => $this->option('question'), 'values' => [$this->option('answer')]],
            ],
        ];

        $lead = $meta->import($payload, 'simulated-form', 'simulated-page');

        $this->info("Imported lead #{$lead->id}: {$lead->name} ({$lead->detail})");
        $this->line('Source: '.$lead->source.'  ·  external_id: '.$lead->external_id);
        $this->newLine();
        $this->line('Meta leads now in CRM: '.Lead::where('source', 'meta')->count());

        return self::SUCCESS;
    }
}
