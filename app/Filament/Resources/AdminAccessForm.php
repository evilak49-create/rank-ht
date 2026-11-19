<?php

namespace App\Filament\Resources;

use Closure;
use Filament\Actions\Action;
use Illuminate\Validation\ValidationException;

class AdminAccessForm
{
    public static function submit(Action $action, Closure $save): void
    {
        try {
            $save();
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors['mountedActions.'.$action->getNestingIndex().'.data.'.$field] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }
}
