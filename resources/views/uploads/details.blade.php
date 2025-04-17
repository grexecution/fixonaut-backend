@extends('layouts.app')

@section('title', 'Upload Details')

@section('content')
<div class="row">
    <div class="col-md-8 offset-md-2">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>File Details</span>
                <a href="{{ route('uploads.dashboard') }}" class="btn btn-sm btn-primary">Back to Dashboard</a>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-4 fw-bold">File Name:</div>
                    <div class="col-md-8">{{ $upload->filename }}</div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4 fw-bold">File Size:</div>
                    <div class="col-md-8">{{ number_format($upload->file_size / 1024, 2) }} KB</div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4 fw-bold">File Type:</div>
                    <div class="col-md-8">{{ $upload->file_type }}</div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4 fw-bold">Status:</div>
                    <div class="col-md-8">
                        <span class="badge {{ $upload->status === 'completed' ? 'bg-success' : ($upload->status === 'failed' ? 'bg-danger' : 'bg-warning') }}">
                            {{ ucfirst($upload->status) }}
                        </span>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4 fw-bold">Upload Date:</div>
                    <div class="col-md-8">{{ $upload->created_at->format('F j, Y, g:i a') }}</div>
                </div>
                @if($upload->site_url)
                <div class="row mb-3">
                    <div class="col-md-4 fw-bold">Site URL:</div>
                    <div class="col-md-8">
                        <a href="{{ $upload->site_url }}" target="_blank" rel="noopener">{{ $upload->site_url }}</a>
                    </div>
                </div>
                @endif
                @if($upload->theme)
                <div class="row mb-3">
                    <div class="col-md-4 fw-bold">Theme:</div>
                    <div class="col-md-8">{{ $upload->theme }}</div>
                </div>
                @endif
                @if($upload->filepath)
                <div class="row mb-3">
                    <div class="col-md-4 fw-bold">File Path:</div>
                    <div class="col-md-8">{{ $upload->filepath }}</div>
                </div>
                @endif
            </div>
        </div>

        @if($upload->chunks->count() > 0)
        <div class="card mt-4">
            <div class="card-header">
                Chunks Information
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Chunk #</th>
                                <th>Size</th>
                                <th>Status</th>
                                <th>Uploaded At</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($upload->chunks->sortBy('chunk_index') as $chunk)
                            <tr>
                                <td>{{ $chunk->chunk_index + 1 }}</td>
                                <td>{{ number_format($chunk->chunk_size / 1024, 2) }} KB</td>
                                <td>
                                    <span class="badge {{ $chunk->status === 'completed' ? 'bg-success' : ($chunk->status === 'failed' ? 'bg-danger' : 'bg-warning') }}">
                                        {{ ucfirst($chunk->status) }}
                                    </span>
                                </td>
                                <td>{{ $chunk->created_at->format('F j, Y, g:i:s a') }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @endif
    </div>
</div>
@endsection
