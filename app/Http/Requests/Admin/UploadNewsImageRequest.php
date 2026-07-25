<?php

namespace App\Http\Requests\Admin;

class UploadNewsImageRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
                'dimensions:max_width=6000,max_height=6000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => __('Select an image.'),
            'image.image' => __('The file must be an image.'),
            'image.mimes' => __('Only JPG, PNG and WebP files are allowed.'),
            'image.max' => __('The image must not exceed 5 MB.'),
            'image.dimensions' => __('The image dimensions must not exceed 6000×6000 pixels.'),
        ];
    }
}
