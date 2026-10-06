{{-- Portfolio module: the Portfolio page -- every published work, "All" + filters. --}}
@extends($site->layout_view)

@section('title', 'Portfolio')

@section('content')
  @include($site->gallery_view, ['uid' => 'work', 'showAll' => true])
@endsection

@push('scripts')
  @include('portfolio.partials.mixitup')
@endpush
