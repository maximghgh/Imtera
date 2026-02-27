<?php

namespace App\Http\Requests\Yandex;

use Illuminate\Foundation\Http\FormRequest;

class StoreYandexSourceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'source_url' => ['required', 'url', 'max:2048', 'regex:/^https?:\/\/(www\.)?yandex\.(ru|com)\/maps\/org\/.+/i'],
        ];
    }

    public function messages(): array
    {
        return [
            'source_url.required' => 'Укажите ссылку на Яндекс.',
            'source_url.url' => 'Ссылка должна быть корректным URL.',
            'source_url.max' => 'Ссылка слишком длинная.',
            'source_url.regex' => 'Нужна ссылка вида https://yandex.ru/maps/org/... .',
        ];
    }
}
