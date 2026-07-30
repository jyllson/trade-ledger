<?php

use App\Filament\Widgets\ReadOnlyModeWidget;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('redirects guests to the login page', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('shows the read-only analytics mode on the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertOk()
        ->assertSee('Read-only analytics mode')
        ->assertSee('eToro write operations are blocked.');
});

it('renders the read-only mode widget without a write mode state', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ReadOnlyModeWidget::class)
        ->assertSee('Read-only analytics mode')
        ->assertDontSee('Write mode');
});
