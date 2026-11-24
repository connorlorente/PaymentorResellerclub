<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Admin\Pages;

use App\Helpers\ExtensionHelper;
use App\Models\Category;
use App\Models\Product;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\ResellerClub\Core\PricingSyncRunner;
use Paymenter\Extensions\Servers\ResellerClub\Core\TldProducts;
use Paymenter\Extensions\Servers\ResellerClub\ResellerClub;

/**
 * Admin > Domain setup: everything that used to need the shell.
 * Create one product per TLD, refresh registrar costs, review/apply proposed prices, test the API.
 */
class DomainSetup extends Page
{
    protected string $view = 'resellerclub::admin.domain-setup';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Domain setup';

    protected static ?string $title = 'Domain setup (ResellerClub)';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-earth-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-earth-fill';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.resellerclub.manage');
    }

    private function server(): ?Server
    {
        return Server::where('extension', 'ResellerClub')->first();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createTlds')
                ->label('Create TLD products')
                ->icon('ri-add-line')
                ->schema([
                    Textarea::make('tlds')->label('Domain extensions')->default("com
net
org
info
biz
co.uk
org.uk
me.uk
uk")->helperText('One per line or comma separated, without the dot. Standard extensions are filled in; existing ones are skipped.')->required(),
                    Select::make('category_id')->label('Category')->options(fn () => Category::query()->pluck('name', 'id')->all())->required(),
                    TextInput::make('years')->label('Longest term to offer (years)')->numeric()->minValue(1)->maxValue(10)->default(10)->required(),
                ])
                ->action(function (array $data) {
                    $server = $this->server();
                    if (!$server) {
                        Notification::make()->title('Add a ResellerClub server first (Extensions > Servers).')->danger()->send();

                        return;
                    }
                    $lines = [];
                    foreach (preg_split('/[\s,]+/', (string) $data['tlds'], -1, PREG_SPLIT_NO_EMPTY) as $tld) {
                        try {
                            $r = (new TldProducts())->create($tld, (int) $data['category_id'], $server, (int) $data['years']);
                            $lines[] = $r['created']
                                ? ".$tld: created hidden (" . count($r['terms']) . ' terms' . ($r['priced'] ? '' : ', no prices yet') . ')'
                                : ".$tld: already exists";
                        } catch (\Throwable $e) {
                            $lines[] = ".$tld: " . $e->getMessage();
                        }
                    }
                    Notification::make()->title('TLD products')->body(implode("\n", $lines))->success()->send();
                }),

            Action::make('syncPrices')
                ->label('Sync prices now')
                ->icon('ri-refresh-line')
                ->requiresConfirmation()
                ->modalDescription('Fetches your ResellerClub cost prices and works out proposed selling prices. Prices are only changed if "Auto-apply" is on in the server settings; otherwise review and apply them below.')
                ->action(function () {
                    (new PricingSyncRunner())();
                    $n = count(Cache::get(PricingSyncRunner::PROPOSALS_KEY, []));
                    Notification::make()->title('Prices synced')->body($n ? "$n price changes proposed." : 'No price changes to review.')->success()->send();
                }),

            Action::make('applyPrices')
                ->label('Apply proposed prices')
                ->icon('ri-check-double-line')
                ->color('warning')
                ->visible(fn () => count(Cache::get(PricingSyncRunner::PROPOSALS_KEY, [])) > 0)
                ->requiresConfirmation()
                ->modalDescription('Updates the plan prices to the proposals listed on this page. Invoices that were already issued are not changed.')
                ->action(function () {
                    $n = PricingSyncRunner::apply(Cache::get(PricingSyncRunner::PROPOSALS_KEY, []));
                    Cache::forever(PricingSyncRunner::PROPOSALS_KEY, []);
                    Notification::make()->title("$n prices updated")->success()->send();
                }),

            Action::make('testConnection')
                ->label('Test connection')
                ->icon('ri-plug-line')
                ->color('gray')
                ->action(function () {
                    $server = $this->server();
                    $result = $server ? ResellerClub::forServer($server)->testConfig() : 'No ResellerClub server configured.';
                    $result === true
                        ? Notification::make()->title('Connected to ResellerClub')->success()->send()
                        : Notification::make()->title('Connection failed')->body((string) $result)->danger()->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        $server = $this->server();
        $rows = [];
        if ($server) {
            $currency = config('settings.default_currency');
            foreach (Product::where('server_id', $server->id)->with(['settings', 'category', 'plans.prices'])->orderBy('name')->get() as $product) {
                $settings = ExtensionHelper::settingsToArray($product->settings);
                $prices = [];
                foreach ($product->plans->sortBy('billing_period') as $plan) {
                    $p = $plan->prices->firstWhere('currency_code', $currency);
                    $prices[] = $plan->name . ': ' . ($p ? number_format((float) $p->price, 2) : '—');
                }
                $rows[] = [
                    'id' => $product->id,
                    'tld' => $settings['tld'] ?? '?',
                    'name' => $product->name,
                    'category' => $product->category?->name,
                    'hidden' => (bool) $product->hidden,
                    'prices' => implode(' · ', $prices),
                ];
            }
        }

        return [
            'server' => $server,
            'rows' => $rows,
            'proposals' => Cache::get(PricingSyncRunner::PROPOSALS_KEY, []),
            'currency' => config('settings.default_currency'),
        ];
    }
}
