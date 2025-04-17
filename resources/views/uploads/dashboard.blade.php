@extends('layouts.app')

@section('title', 'Upload Dashboard')

@section('content')
<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                Recent Uploads
            </div>
            <div class="card-body">
                @if($recentUploads->count() > 0)
                    <div class="list-group">
                        @foreach($recentUploads as $upload)
                            <a href="{{ route('uploads.details', $upload->id) }}" class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <h5 class="mb-1">{{ $upload->filename }}</h5>
                                    <small>{{ $upload->created_at->diffForHumans() }}</small>
                                </div>
                                <p class="mb-1">Size: {{ number_format($upload->file_size / 1024, 2) }} KB</p>
                                <small>Status: {{ $upload->status }}</small>
                            </a>
                        @endforeach
                    </div>
                @else
                    <p class="text-center">No uploads found.</p>
                @endif
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                Recent Scans
            </div>
            <div class="card-body">
                @if($recentScans->count() > 0)
                    <div class="list-group">
                        @foreach($recentScans as $scan)
                            <a href="{{ route('uploads.scan-details', $scan->id) }}" class="list-group-item list-group-item-action">
                                <div class="d-flex w-100 justify-content-between">
                                    <h5 class="mb-1">{{ $scan->file_path }}</h5>
                                    <small>{{ $scan->scan_date->diffForHumans() }}</small>
                                </div>
                                <p class="mb-1">Type: {{ $scan->file_type }}</p>
                                <small>Site: {{ $scan->site_url }}</small>
                            </a>
                        @endforeach
                    </div>
                @else
                    <p class="text-center">No scans found.</p>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                Upload Files
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="{{ route('uploads.standard-form') }}" class="btn btn-primary">Standard Upload</a>
                    <a href="{{ route('uploads.chunked-form') }}" class="btn btn-primary">Chunked Upload</a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
