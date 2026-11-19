<?php

namespace App\Filament\Resources;

use Closure;
use Filament\Actions\Action;
use Illuminate\Validation\ValidationException;

class ActionForm
{
    public static function submit(Action $action, Closure $save): void
    {
        try {
            $save();
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $formField = str_starts_with($field, 'steps.') ? 'steps' : $field;
                $path = 'mountedActions.'.$action->getNestingIndex().'.data.'.$formField;
                $errors[$path] = [...($errors[$path] ?? []), ...$messages];
            }

            throw ValidationException::withMessages($errors);
        }
    }
}
