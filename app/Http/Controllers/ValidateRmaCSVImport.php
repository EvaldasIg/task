<?php

namespace App\Http\Controllers;

use App\Rules\UTF8;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\LazyCollection;
use League\Csv\Reader;

class ValidateRmaCSVImport extends Controller
{
    private $errors = [];
    private $products = [];
    private $break = false;

    /**
     * Handle the incoming request.
     *
     * @param  Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'csv' => ['required', 'file', 'mimes:csv,txt', new UTF8()]
        ]);

        if ($validator->fails()) {
            return response()->json(['code' => 400, 'errors' => $validator->errors()], 400);
        }

        LazyCollection::make(function () use ($request) {
            $filePath = $request->file('csv')->path();
            $handle = fopen($filePath, 'r');

            while ($line = fgetcsv($handle)) {
                yield $line;
            }
        })
            ->chunk(100)
            ->map(function ($records) {
                return $records->map(function ($item, $key) {
                    $item['name'] = $item[0] ?? null;
                    unset($item[0]);
                    $item['imei_sn'] = $item[1] ?? null;
                    unset($item[1]);
                    $item['order_id'] = $item[2] ?? null;
                    unset($item[2]);
                    $item['description'] = $item[3] ?? null;
                    unset($item[3]);
                    return $item;
                });
            })
            ->each(function ($records) use ($validator) {
                $records = $records->toArray();

                $validator = Validator::make(['products' => $records], [
                    'products' => 'required|array|min:1',
                    'products.*.name' => 'string|required|max:10',
                    'products.*.order_id' => 'sometimes|digits:5',
                    'products.*.imei_sn' => 'required|numeric|digits_between:8,15',
                    'products.*.description' => 'string|max:1000|required'
                ]);

                if ($validator->fails()) {
                    $this->errors = array_merge($this->errors, collect($validator->errors())->keyBy(function ($item, $key) {
                        $rowNumber = (int)(explode('.', $key)[1]) + 1;
                        return trans('validation.custom.row') . " $rowNumber";
                    })->sortKeys()->toArray());
                }

                $this->products = !$this->errors ? array_merge($this->products, $records) : null;
            });

        if ($this->errors) {
            return response()->json(['code' => 400, 'errors' => ['product_information' => $this->errors]], 400);
        }

        return response()->json(['products' => $this->products]);
    }
}
