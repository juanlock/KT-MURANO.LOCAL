{{-- Portfolio module: the Portfolio page -- every published work, "All" + filters. --}}
@extends('portfolio.layout')

@section('title', 'Portfolio')

@section('content')
  @include('portfolio.partials.gallery', ['uid' => 'work', 'showAll' => true])
@endsection

@push('scripts')
  @include('portfolio.partials.mixitup')
@endpush
