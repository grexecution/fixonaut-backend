@extends('layouts.app')

@section('title', 'Scan Details')

@section('content')
<div class="row">
    <div class="col-md-8 offset-md-2">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Scan Details</span>
                <a href="{{ route('uploads.dashboard') }}" class="btn btn-sm btn-primary">Back to Dashboard</a>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-4 fw-bold">File Path:</div>
                    <div class="col-md-8">{{ $scan->file_path }}</div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4 fw-bold">File Type:</div>
                    <div class="col-md-8">{{ $scan->file_type }}</div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4 fw-bold">Scan Date:</div>
                    <div class="col-md-8">{{ $scan->scan_date->format('F j, Y, g:i a') }}</div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4 fw-bold">Site URL:</div>
                    <div class="col-md-8">
                        <a href="{{ $scan->site_url }}" target="_blank" rel="noopener">{{ $scan->site_url }}</a>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4 fw-bold">Theme:</div>
                    <div class="col-md-8">{{ $scan->theme }}</div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4 fw-bold">Created At:</div>
                    <div class="col-md-8">{{ $scan->created_at->format('F j, Y, g:i a') }}</div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4 fw-bold">Updated At:</div>
                    <div class="col-md-8">{{ $scan->updated_at->format('F j, Y, g:i a') }}</div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
