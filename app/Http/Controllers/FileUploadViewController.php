<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class FileUploadViewController extends Controller
{
    /**
     * Display the chunked upload form
     *
     * @return \Illuminate\View\View
     */
    public function showChunkedUploadForm()
    {
        return view('uploads.chunked-upload');
    }
    
    /**
     * Display the standard upload form
     *
     * @return \Illuminate\View\View
     */
    public function showStandardUploadForm()
    {
        return view('uploads.standard-upload');
    }
}
