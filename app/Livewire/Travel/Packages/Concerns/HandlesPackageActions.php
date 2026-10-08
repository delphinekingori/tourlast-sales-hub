<?php

namespace App\Livewire\Travel\Packages\Concerns;

use App\Actions\Travel\Packages\ArchivePackage;
use App\Actions\Travel\Packages\DiscardPackageChanges;
use App\Actions\Travel\Packages\DuplicatePackage;
use App\Actions\Travel\Packages\PublishPackage;
use App\Actions\Travel\Packages\SubmitPackage;
use App\Actions\Travel\Packages\UnpublishPackage;
use App\Actions\Travel\Packages\WithdrawPackage;
use App\Enums\Permission;
use App\Models\Package;
use App\Support\Travel\PackageDuplicateFinder;
use App\Support\Travel\PublishGate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Submit, withdraw, publish, unpublish, archive and duplicate, shared by the
 * packages table and the package page. Every check happens in the actions.
 */
trait HandlesPackageActions
{
    #[Locked]
    public ?int $actionPackageId = null;

    public bool $showPublish = false;

    public bool $showUnpublish = false;

    public bool $showDuplicate = false;

    /** @var array{channel: string, url: string, override: string} */
    public array $publish = ['channel' => '', 'url' => '', 'override' => ''];

    public string $unpublishReason = '';

    public string $duplicateName = '';

    public bool $duplicateAccepted = false;

    /** @var list<string> */
    public array $publishMissing = [];

    public function submitPackage(int $id, SubmitPackage $submit): void
    {
        $this->runPackageAction(fn () => $submit->handle(Auth::user(), $this->packageFor($id)), 'Submitted for Sales Admin review.');
    }

    public function withdrawPackage(int $id, WithdrawPackage $withdraw): void
    {
        $this->runPackageAction(fn () => $withdraw->handle(Auth::user(), $this->packageFor($id)), 'Withdrawn from review. You can edit it again.');
    }

    public function discardChanges(int $id, DiscardPackageChanges $discard): void
    {
        $this->runPackageAction(fn () => $discard->handle(Auth::user(), $this->packageFor($id)), 'Draft changes discarded. The live version is unchanged.');
    }

    public function archivePackage(int $id, ArchivePackage $archive): void
    {
        $this->runPackageAction(fn () => $archive->handle(Auth::user(), $this->packageFor($id)), 'Package archived.');
    }

    public function openPublish(int $id): void
    {
        $package = $this->packageFor($id);
        $this->actionPackageId = $package->id;
        $this->publish = ['channel' => '', 'url' => '', 'override' => ''];
        $this->publishMissing = PublishGate::missing($package);
        $this->resetErrorBag();
        $this->showPublish = true;
    }

    public function confirmPublish(PublishPackage $publishPackage): void
    {
        $this->validate([
            'publish.channel' => ['required', 'string', 'max:120'],
            'publish.url' => ['nullable', 'url', 'max:255'],
            'publish.override' => ['nullable', 'string', 'max:1000'],
        ], [], ['publish.channel' => 'channel', 'publish.url' => 'link']);

        $this->runPackageAction(function () use ($publishPackage): void {
            $publishPackage->handle(Auth::user(), $this->packageFor((int) $this->actionPackageId), $this->publish['channel'], $this->publish['url'], $this->publish['override']);
            $this->showPublish = false;
        }, 'Marked as published.');
    }

    public function openUnpublish(int $id): void
    {
        $this->actionPackageId = $this->packageFor($id)->id;
        $this->unpublishReason = '';
        $this->showUnpublish = true;
    }

    public function confirmUnpublish(UnpublishPackage $unpublish): void
    {
        $this->runPackageAction(function () use ($unpublish): void {
            $unpublish->handle(Auth::user(), $this->packageFor((int) $this->actionPackageId), $this->unpublishReason);
            $this->showUnpublish = false;
        }, 'Package unpublished.');
    }

    public function openDuplicate(int $id): void
    {
        $package = $this->packageFor($id);
        $this->actionPackageId = $package->id;
        $this->duplicateName = $package->name.' (copy)';
        $this->duplicateAccepted = false;
        $this->resetErrorBag();
        $this->showDuplicate = true;
    }

    public function confirmDuplicate(DuplicatePackage $duplicate): void
    {
        $this->validate(['duplicateName' => ['required', 'string', 'max:255']], [], ['duplicateName' => 'name']);

        try {
            $copy = $duplicate->handle(Auth::user(), $this->packageFor((int) $this->actionPackageId), $this->duplicateName, $this->duplicateAccepted);
        } catch (ValidationException $e) {
            $this->addError('duplicateName', collect($e->errors())->flatten()->first());

            return;
        }

        session()->flash('toast', ['message' => 'Copy created. Confirm prices, capacity, driver, guide and contract.']);
        $this->redirectRoute('travel.packages.edit', $copy, navigate: true);
    }

    /**
     * Possible duplicates of the name typed in the duplicate dialog.
     *
     * @return Collection<int, array{package: Package, exact: bool, reason: string}>
     */
    public function duplicateMatches(): Collection
    {
        if (! $this->showDuplicate || ! $this->actionPackageId) {
            return collect();
        }

        $source = Package::query()->find($this->actionPackageId);

        return $source ? PackageDuplicateFinder::find($this->duplicateName, $source->travel_provider_id, $source->destination) : collect();
    }

    public function canOverrideContract(): bool
    {
        return Auth::user()->can(Permission::OverridePackageContract->value);
    }

    protected function packageFor(int $id): Package
    {
        return Package::query()->findOrFail($id);
    }

    private function runPackageAction(callable $action, string $message): void
    {
        try {
            $action();
        } catch (ValidationException $e) {
            $this->dispatch('toast', message: collect($e->errors())->flatten()->first(), tone: 'danger');
            foreach ($e->errors() as $key => $messages) {
                $this->addError($key, $messages[0]);
            }

            return;
        }

        $this->dispatch('toast', message: $message);
    }
}
