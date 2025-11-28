<?php

namespace App\Filament\Pages;

use App\Models\PriceList;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use App\Jobs\ProcessPriceList;


class UploadPriceList extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationLabel = 'Upload cenovnika';
    protected static ?string $navigationGroup = 'Cenovnici';
    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';
    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.upload-price-list';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                FileUpload::make('file')
                    ->label('Cenovnik fajl')
                    ->disk('public')
                    ->directory('price-lists')
                    ->required()
                    ->acceptedFileTypes([
                        'application/pdf',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'image/*',
                    ])
                    ->maxSize(10240),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        // Uzmi finalno stanje forme (Filament ovde završi upload i vrati pravi path)
        $this->data = $this->form->getState();

        $path = $this->data['file'] ?? null;

        // Za svaki slučaj, ako je iz nekog razloga niz, uzmi prvi element
        if (is_array($path)) {
            $path = $path[0] ?? null;
        }

        if (! $path) {
            Notification::make()
                ->title('Nije izabran fajl.')
                ->danger()
                ->send();

            return;
        }

        $priceList = PriceList::create([
            'original_filename' => basename($path),
            'original_path'     => $path,
            'status'            => 'pending',
            'source'            => 'upload',
        ]);
        ProcessPriceList::dispatch($priceList->id);

        Notification::make()
            ->title('Cenovnik uspešno otpremljen!')
            ->body('ID: '.$priceList->id)
            ->success()
            ->send();

        // očisti formu
        $this->reset('data');
        $this->form->fill();
    }
}
