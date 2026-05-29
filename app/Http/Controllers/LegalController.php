<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class LegalController extends Controller
{
    public function contract(): View  { return view('legal.contract', ['pageTitle' => 'Contratto di Adesione']); }
    public function amlKyc(): View    { return view('legal.aml-kyc', ['pageTitle' => 'Politica AML/KYC']); }
    public function limits(): View    { return view('legal.limits', ['pageTitle' => 'Limiti Transazionali']); }
    public function complaints(): View { return view('legal.complaints', ['pageTitle' => 'Procedura Reclami']); }
}
