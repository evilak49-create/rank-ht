<?php

namespace App\Filament\Resources\SupportConversationResource\Pages;

use App\Filament\Resources\SupportConversationResource;
use App\Models\SupportConversation;
use App\Models\User;
use App\SupportDesk\SupportConversations;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ViewSupportConversation extends ViewRecord
{
    private const PAGE_SIZE = 75;

    protected static string $resource = SupportConversationResource::class;

    protected string $view = 'filament.support.conversation';

    public string $reply = '';

    #[Locked]
    public ?int $before = null;

    public function hydrate(): void
    {
        $this->operator();
    }

    public function takeOver(SupportConversations $support): void
    {
        $this->perform(fn () => $support->takeOver($this->conversation(), $this->operator()));
    }

    public function release(SupportConversations $support): void
    {
        $this->perform(fn () => $support->release($this->conversation(), $this->operator()));
    }

    public function sendReply(SupportConversations $support): void
    {
        $this->validate(['reply' => ['required', 'string', 'max:2000']], [], ['reply' => '回复']);
        $this->perform(function () use ($support): void {
            $support->sendStaffReply($this->conversation(), $this->operator(), $this->reply);
            $this->reply = '';
            $this->before = null;
        });
    }

    public function earlier(SupportConversations $support): void
    {
        $messages = $support->staffMessages($this->conversation(), $this->operator(), self::PAGE_SIZE + 1, $this->before);
        if ($messages->count() > self::PAGE_SIZE) {
            $this->before = $messages[self::PAGE_SIZE - 1]->id;
        }
    }

    public function latestMessages(): void
    {
        $this->operator();
        $this->before = null;
    }

    protected function getViewData(): array
    {
        $operator = $this->operator();
        $thread = $this->conversation();
        $messages = app(SupportConversations::class)->staffMessages($thread, $operator, self::PAGE_SIZE + 1, $this->before);

        return [
            'thread' => $thread,
            'messages' => $messages->take(self::PAGE_SIZE)->reverse(),
            'hasEarlier' => $messages->count() > self::PAGE_SIZE,
            'canTakeOver' => $thread->operator_id === null || ! $thread->operator?->hasPermission('support.manage'),
            'ownedByOperator' => $thread->operator_id === $operator->id,
        ];
    }

    private function conversation(): SupportConversation
    {
        return $this->getRecord()->refresh()->load(['member', 'operator']);
    }

    private function operator(): User
    {
        $operator = Filament::auth()->user();
        abort_unless($operator instanceof User && $operator->hasPermission('support.manage'), 403);

        return $operator;
    }

    private function perform(callable $action): void
    {
        try {
            $action();
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            Notification::make()->warning()->title($exception->getMessage())->send();
        }
    }
}
