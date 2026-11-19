<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Admin\Resources\OperationResource\Pages;

use App\Models\Category;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\ResellerClub\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\ResellerClub\Core\PricingSyncRunner;
use Paymenter\Extensions\Servers\ResellerClub\Core\TldProducts;

class ListOperations extends ListRecords
{
    protected static string $resource = OperationResource::class;

    protected function getHeaderActions(): array
    {
        $manage = fn () => Auth::user()->hasPermission('admin.resellerclub.manage');
        $proposals = fn () => Cache::get(PricingSyncRunner::PROPOSALS_KEY, []);

        return [
            Action::make('createTldProducts')
                ->label('Create TLD products')
                ->icon('ri-add-line')
                ->visible($manage)
                ->schema([
                    Textarea::make('tlds')->label('TLDs (without the dot; separate with spaces, commas or new lines)')->required()->placeholder('com net org co.uk'),
                    Select::make('category_id')->label('Category')->options(fn () => Category::pluck('name', 'id'))->required(),
                    TextInput::make('years')->label('Longest term (years)')->numeric()->default(10)->minValue(1)->maxValue(10)->required(),
                ])
                ->action(function (array $data) {
                    $server = Server::where('extension', 'ResellerClub')->first();
                    if (!$server) {
                        Notification::make()->title('No ResellerClub server is configured')->danger()->send();

                        return;
                    }
                    $created = $skipped = 0;
                    $invalid = [];
                    foreach (preg_split('/[\s,]+/', trim($data['tlds'])) as $tld) {
                        try {
                            (new TldProducts())->create($tld, (int) $data['category_id'], $server, (int) $data['years'])['created'] ? $created++ : $skipped++;
                        } catch (\InvalidArgumentException) {
                            $invalid[] = $tld;
                        }
                    }
                    Notification::make()->title("Created $created, already existed $skipped" . ($invalid ? ', invalid: ' . implode(' ', $invalid) : ''))
                        ->success()->send();
                }),

            Action::make('applyPrices')
                ->label(fn () => 'Apply ' . count($proposals()) . ' proposed prices')
                ->icon('ri-price-tag-3-line')
                ->visible(fn () => $manage() && count($proposals()) > 0)
                ->requiresConfirmation()
                ->modalDescription(fn () => collect($proposals())->take(15)
                    ->map(fn ($p) => "{$p['product']} {$p['years']}y: " . ($p['current'] ?? 'none') . ' to ' . $p['proposed'])->implode("\n")
                    . (count($proposals()) > 15 ? "\n... and " . (count($proposals()) - 15) . ' more' : '')
                    . "\nIssued invoices are not changed.")
                ->action(function () use ($proposals) {
                    $n = PricingSyncRunner::apply($proposals());
                    Cache::forget(PricingSyncRunner::PROPOSALS_KEY);
                    Notification::make()->title("Applied $n price changes")->success()->send();
                }),
        ];
    }
}
