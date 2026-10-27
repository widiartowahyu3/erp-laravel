@extends('errors.minimal')

@section('code', (string) ($status ?? '422'))
@section('message', $message ?? 'Permintaan tidak dapat diproses.')
