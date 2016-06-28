<?php namespace App\Http\Controllers;

use Auth;
use View;
use DB;
use URL;
use Input;
use Utils;
use Request;
use Response;
use Session;
use Datatable;
use Validator;
use Cache;
use Redirect;
use Exception;
use App\Models\Gateway;
use App\Models\Invitation;
use App\Models\Document;
use App\Models\PaymentMethod;
use App\Models\Contact;
use App\Ninja\Repositories\InvoiceRepository;
use App\Ninja\Repositories\PaymentRepository;
use App\Ninja\Repositories\ActivityRepository;
use App\Ninja\Repositories\DocumentRepository;
use App\Events\InvoiceInvitationWasViewed;
use App\Events\QuoteInvitationWasViewed;
use App\Services\PaymentService;
use Barracuda\ArchiveStream\ZipArchive;

class ClientPortalController extends BaseController
{
    private $invoiceRepo;
    private $paymentRepo;
    private $documentRepo;

    public function __construct(InvoiceRepository $invoiceRepo, PaymentRepository $paymentRepo, ActivityRepository $activityRepo, DocumentRepository $documentRepo, PaymentService $paymentService)
    {
        $this->invoiceRepo = $invoiceRepo;
        $this->paymentRepo = $paymentRepo;
        $this->activityRepo = $activityRepo;
        $this->documentRepo = $documentRepo;
        $this->paymentService = $paymentService;
    }

    public function view($invitationKey)
    {
        if (!$invitation = $this->invoiceRepo->findInvoiceByInvitation($invitationKey)) {
            return $this->returnError();
        }

        $invoice = $invitation->invoice;
        $client = $invoice->client;
        $account = $invoice->account;

        if (!$account->checkSubdomain(Request::server('HTTP_HOST'))) {
            return response()->view('error', [
                'error' => trans('texts.invoice_not_found'),
                'hideHeader' => true,
                'clientViewCSS' => $account->clientViewCSS(),
                'clientFontUrl' => $account->getFontsUrl(),
            ]);
        }

        if (!Input::has('phantomjs') && !Input::has('silent') && !Session::has($invitationKey)
            && (!Auth::check() || Auth::user()->account_id != $invoice->account_id)) {
            if ($invoice->isType(INVOICE_TYPE_QUOTE)) {
                event(new QuoteInvitationWasViewed($invoice, $invitation));
            } else {
                event(new InvoiceInvitationWasViewed($invoice, $invitation));
            }
        }

        Session::put($invitationKey, true); // track this invitation has been seen
        Session::put('contact_key', $invitation->contact->contact_key);// track current contact

        $account->loadLocalizationSettings($client);

        $invoice->invoice_date = Utils::fromSqlDate($invoice->invoice_date);
        $invoice->due_date = Utils::fromSqlDate($invoice->due_date);
        $invoice->features = [
            'customize_invoice_design' => $account->hasFeature(FEATURE_CUSTOMIZE_INVOICE_DESIGN),
            'remove_created_by' => $account->hasFeature(FEATURE_REMOVE_CREATED_BY),
            'invoice_settings' => $account->hasFeature(FEATURE_INVOICE_SETTINGS),
        ];
        $invoice->invoice_fonts = $account->getFontsData();

        if ($invoice->invoice_design_id == CUSTOM_DESIGN) {
            $invoice->invoice_design->javascript = $account->custom_design;
        } else {
            $invoice->invoice_design->javascript = $invoice->invoice_design->pdfmake;
        }
        $contact = $invitation->contact;
        $contact->setVisible([
            'first_name',
            'last_name',
            'email',
            'phone',
        ]);

        $data = array();
        $paymentTypes = $this->getPaymentTypes($account, $client, $invitation);
        $paymentURL = '';
        if (count($paymentTypes) == 1) {
            $paymentURL = $paymentTypes[0]['url'];
            if (!$account->isGatewayConfigured(GATEWAY_PAYPAL_EXPRESS)) {
                $paymentURL = URL::to($paymentURL);
            }
        }

        if ($wepayGateway = $account->getGatewayConfig(GATEWAY_WEPAY)){
            $data['enableWePayACH'] = $wepayGateway->getAchEnabled();
        }

        $showApprove = $invoice->quote_invoice_id ? false : true;
        if ($invoice->due_date) {
            $showApprove = time() < strtotime($invoice->due_date);
        }
        if ($invoice->invoice_status_id >= INVOICE_STATUS_APPROVED) {
            $showApprove = false;
        }

        $data += array(
            'account' => $account,
            'showApprove' => $showApprove,
            'showBreadcrumbs' => false,
            'clientFontUrl' => $account->getFontsUrl(),
            'invoice' => $invoice->hidePrivateFields(),
            'invitation' => $invitation,
            'invoiceLabels' => $account->getInvoiceLabels(),
            'contact' => $contact,
            'paymentTypes' => $paymentTypes,
            'paymentURL' => $paymentURL,
            'phantomjs' => Input::has('phantomjs'),
        );

        if ($paymentDriver = $account->paymentDriver($invitation, GATEWAY_TYPE_CREDIT_CARD)) {
            $data += [
                'transactionToken' => $paymentDriver->createTransactionToken(),
                'partialView' => $paymentDriver->partialView(),
                'accountGateway' => $paymentDriver->accountGateway,
            ];
        }



        if($account->hasFeature(FEATURE_DOCUMENTS) && $this->canCreateZip()){
            $zipDocs = $this->getInvoiceZipDocuments($invoice, $size);

            if(count($zipDocs) > 1){
                $data['documentsZipURL'] = URL::to("client/documents/{$invitation->invitation_key}");
                $data['documentsZipSize'] = $size;
            }
        }

        return View::make('invoices.view', $data);
    }

    public function contactIndex($contactKey) {
        if (!$contact = Contact::where('contact_key', '=', $contactKey)->first()) {
            return $this->returnError();
        }

        $client = $contact->client;

        Session::put('contact_key', $contactKey);// track current contact

        return redirect()->to($client->account->enable_client_portal_dashboard?'/client/dashboard':'/client/invoices/');
    }

    private function getPaymentTypes($account, $client, $invitation)
    {
        $links = [];

        foreach ($account->account_gateways as $accountGateway) {
            $paymentDriver = $accountGateway->paymentDriver($invitation);
            $links = array_merge($links, $paymentDriver->tokenLinks());
            $links = array_merge($links, $paymentDriver->paymentLinks());
        }

        return $links;
    }

    public function download($invitationKey)
    {
        if (!$invitation = $this->invoiceRepo->findInvoiceByInvitation($invitationKey)) {
            return response()->view('error', [
                'error' => trans('texts.invoice_not_found'),
                'hideHeader' => true,
            ]);
        }

        $invoice = $invitation->invoice;
        $pdfString = $invoice->getPDFString();

        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($pdfString));
        header('Content-disposition: attachment; filename="' . $invoice->getFileName() . '"');
        header('Cache-Control: public, must-revalidate, max-age=0');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');

        return $pdfString;
    }

    public function dashboard()
    {
        if (!$contact = $this->getContact()) {
            return $this->returnError();
        }

        $client = $contact->client;
        $account = $client->account;
        $color = $account->primary_color ? $account->primary_color : '#0b4d78';

        if (!$account->enable_client_portal || !$account->enable_client_portal_dashboard) {
            return $this->returnError();
        }

        $paymentDriver = $account->paymentDriver(false, GATEWAY_TYPE_TOKEN);
        $customer = $paymentDriver->customer($client->id);

        $data = [
            'color' => $color,
            'contact' => $contact,
            'account' => $account,
            'client' => $client,
            'clientFontUrl' => $account->getFontsUrl(),
            'gateway' => $account->getTokenGateway(),
            'paymentMethods' => $customer ? $customer->payment_methods : false,
            'transactionToken' => $paymentDriver->createTransactionToken(),
        ];

        return response()->view('invited.dashboard', $data);
    }

    public function activityDatatable()
    {
        if (!$contact = $this->getContact()) {
            return $this->returnError();
        }

        $client = $contact->client;

        $query = $this->activityRepo->findByClientId($client->id);
        $query->where('activities.adjustment', '!=', 0);

        return Datatable::query($query)
            ->addColumn('activities.id', function ($model) { return Utils::timestampToDateTimeString(strtotime($model->created_at)); })
            ->addColumn('activity_type_id', function ($model) {
                $data = [
                    'client' => Utils::getClientDisplayName($model),
                    'user' => $model->is_system ? ('<i>' . trans('texts.system') . '</i>') : ($model->account_name),
                    'invoice' => $model->invoice,
                    'contact' => Utils::getClientDisplayName($model),
                    'payment' => $model->payment ? ' ' . $model->payment : '',
                    'credit' => $model->payment_amount ? Utils::formatMoney($model->credit, $model->currency_id, $model->country_id) : '',
                    'payment_amount' => $model->payment_amount ? Utils::formatMoney($model->payment_amount, $model->currency_id, $model->country_id) : null,
                    'adjustment' => $model->adjustment ? Utils::formatMoney($model->adjustment, $model->currency_id, $model->country_id) : null,
                ];

                return trans("texts.activity_{$model->activity_type_id}", $data);
             })
            ->addColumn('balance', function ($model) { return Utils::formatMoney($model->balance, $model->currency_id, $model->country_id); })
            ->addColumn('adjustment', function ($model) { return $model->adjustment != 0 ? Utils::wrapAdjustment($model->adjustment, $model->currency_id, $model->country_id) : ''; })
            ->make();
    }

    public function recurringInvoiceIndex()
    {
        if (!$contact = $this->getContact()) {
            return $this->returnError();
        }

        $account = $contact->account;

        if (!$account->enable_client_portal) {
            return $this->returnError();
        }

        $color = $account->primary_color ? $account->primary_color : '#0b4d78';

        $data = [
            'color' => $color,
            'account' => $account,
            'client' => $contact->client,
            'clientFontUrl' => $account->getFontsUrl(),
            'title' => trans('texts.recurring_invoices'),
            'entityType' => ENTITY_RECURRING_INVOICE,
            'columns' => Utils::trans(['frequency', 'start_date', 'end_date', 'invoice_total', 'auto_bill']),
        ];

        return response()->view('public_list', $data);
    }

    public function invoiceIndex()
    {
        if (!$contact = $this->getContact()) {
            return $this->returnError();
        }

        $account = $contact->account;

        if (!$account->enable_client_portal) {
            return $this->returnError();
        }

        $color = $account->primary_color ? $account->primary_color : '#0b4d78';

        $data = [
            'color' => $color,
            'account' => $account,
            'client' => $contact->client,
            'clientFontUrl' => $account->getFontsUrl(),
            'title' => trans('texts.invoices'),
            'entityType' => ENTITY_INVOICE,
            'columns' => Utils::trans(['invoice_number', 'invoice_date', 'invoice_total', 'balance_due', 'due_date']),
        ];

        return response()->view('public_list', $data);
    }

    public function invoiceDatatable()
    {
        if (!$contact = $this->getContact()) {
            return '';
        }

        return $this->invoiceRepo->getClientDatatable($contact->id, ENTITY_INVOICE, Input::get('sSearch'));
    }

    public function recurringInvoiceDatatable()
    {
        if (!$contact = $this->getContact()) {
            return '';
        }

        return $this->invoiceRepo->getClientRecurringDatatable($contact->id);
    }


    public function paymentIndex()
    {
        if (!$contact = $this->getContact()) {
            return $this->returnError();
        }

        $account = $contact->account;

        if (!$account->enable_client_portal) {
            return $this->returnError();
        }

        $color = $account->primary_color ? $account->primary_color : '#0b4d78';
        $data = [
            'color' => $color,
            'account' => $account,
            'clientFontUrl' => $account->getFontsUrl(),
            'entityType' => ENTITY_PAYMENT,
            'title' => trans('texts.payments'),
            'columns' => Utils::trans(['invoice', 'transaction_reference', 'method', 'payment_amount', 'payment_date', 'status'])
        ];

        return response()->view('public_list', $data);
    }

    public function paymentDatatable()
    {
        if (!$contact = $this->getContact()) {
            return $this->returnError();
        }
        $payments = $this->paymentRepo->findForContact($contact->id, Input::get('sSearch'));

        return Datatable::query($payments)
                ->addColumn('invoice_number', function ($model) { return $model->invitation_key ? link_to('/view/'.$model->invitation_key, $model->invoice_number)->toHtml() : $model->invoice_number; })
                ->addColumn('transaction_reference', function ($model) { return $model->transaction_reference ? $model->transaction_reference : '<i>Manual entry</i>'; })
                ->addColumn('payment_type', function ($model) { return ($model->payment_type && !$model->last4) ? $model->payment_type : ($model->account_gateway_id ? '<i>Online payment</i>' : ''); })
                ->addColumn('amount', function ($model) { return Utils::formatMoney($model->amount, $model->currency_id, $model->country_id); })
                ->addColumn('payment_date', function ($model) { return Utils::dateToString($model->payment_date); })
                ->addColumn('status', function ($model) { return $this->getPaymentStatusLabel($model); })
                ->orderColumns( 'invoice_number', 'transaction_reference', 'payment_type', 'amount', 'payment_date')
                ->make();
    }

    private function getPaymentStatusLabel($model)
    {
        $label = trans("texts.status_" . strtolower($model->payment_status_name));
        $class = 'default';
        switch ($model->payment_status_id) {
            case PAYMENT_STATUS_PENDING:
                $class = 'info';
                break;
            case PAYMENT_STATUS_COMPLETED:
                $class = 'success';
                break;
            case PAYMENT_STATUS_FAILED:
                $class = 'danger';
                break;
            case PAYMENT_STATUS_PARTIALLY_REFUNDED:
                $label = trans('texts.status_partially_refunded_amount', [
                    'amount' => Utils::formatMoney($model->refunded, $model->currency_id, $model->country_id),
                ]);
                $class = 'primary';
                break;
            case PAYMENT_STATUS_REFUNDED:
                $class = 'default';
                break;
        }
        return "<h4><div class=\"label label-{$class}\">$label</div></h4>";
    }

    public function quoteIndex()
    {
        if (!$contact = $this->getContact()) {
            return $this->returnError();
        }

        $account = $contact->account;

        if (!$account->enable_client_portal) {
            return $this->returnError();
        }

        $color = $account->primary_color ? $account->primary_color : '#0b4d78';
        $data = [
          'color' => $color,
          'account' => $account,
          'clientFontUrl' => $account->getFontsUrl(),
          'title' => trans('texts.quotes'),
          'entityType' => ENTITY_QUOTE,
          'columns' => Utils::trans(['quote_number', 'quote_date', 'quote_total', 'due_date']),
        ];

        return response()->view('public_list', $data);
    }


    public function quoteDatatable()
    {
        if (!$contact = $this->getContact()) {
            return false;
        }

        return $this->invoiceRepo->getClientDatatable($contact->id, ENTITY_QUOTE, Input::get('sSearch'));
    }

    public function documentIndex()
    {
        if (!$contact = $this->getContact()) {
            return $this->returnError();
        }

        $account = $contact->account;

        if (!$account->enable_client_portal) {
            return $this->returnError();
        }

        $color = $account->primary_color ? $account->primary_color : '#0b4d78';
        $data = [
          'color' => $color,
          'account' => $account,
          'clientFontUrl' => $account->getFontsUrl(),
          'title' => trans('texts.documents'),
          'entityType' => ENTITY_DOCUMENT,
          'columns' => Utils::trans(['invoice_number', 'name', 'document_date', 'document_size']),
        ];

        return response()->view('public_list', $data);
    }


    public function documentDatatable()
    {
        if (!$contact = $this->getContact()) {
            return false;
        }

        return $this->documentRepo->getClientDatatable($contact->id, ENTITY_DOCUMENT, Input::get('sSearch'));
    }

    private function returnError($error = false)
    {
        return response()->view('error', [
            'error' => $error ?: trans('texts.invoice_not_found'),
            'hideHeader' => true,
            'account' => $this->getContact()->account,
        ]);
    }

    private function getContact() {
        $contactKey = session('contact_key');

        if (!$contactKey) {
            return false;
        }

        $contact = Contact::where('contact_key', '=', $contactKey)->first();

        if (!$contact || $contact->is_deleted) {
            return false;
        }

        return $contact;
    }

    public function getDocumentVFSJS($publicId, $name){
        if (!$contact = $this->getContact()) {
            return $this->returnError();
        }

        $document = Document::scope($publicId, $contact->account_id)->first();


        if(!$document->isPDFEmbeddable()){
            return Response::view('error', array('error'=>'Image does not exist!'), 404);
        }

        $authorized = false;
        if($document->expense && $document->expense->client_id == $contact->client_id){
            $authorized = true;
        } else if($document->invoice && $document->invoice->client_id ==$contact->client_id){
            $authorized = true;
        }

        if(!$authorized){
            return Response::view('error', array('error'=>'Not authorized'), 403);
        }

        if(substr($name, -3)=='.js'){
            $name = substr($name, 0, -3);
        }

        $content = $document->preview?$document->getRawPreview():$document->getRaw();
        $content = 'ninjaAddVFSDoc('.json_encode(intval($publicId).'/'.strval($name)).',"'.base64_encode($content).'")';
        $response = Response::make($content, 200);
        $response->header('content-type', 'text/javascript');
        $response->header('cache-control', 'max-age=31536000');

        return $response;
    }

    protected function canCreateZip(){
        return function_exists('gmp_init');
    }

    protected function getInvoiceZipDocuments($invoice, &$size=0){
        $documents = $invoice->documents;

        foreach($invoice->expenses as $expense){
            $documents = $documents->merge($expense->documents);
        }

        $documents = $documents->sortBy('size');

        $size = 0;
        $maxSize = MAX_ZIP_DOCUMENTS_SIZE * 1000;
        $toZip = array();
        foreach($documents as $document){
            if($size + $document->size > $maxSize)break;

            if(!empty($toZip[$document->name])){
                // This name is taken
                if($toZip[$document->name]->hash != $document->hash){
                    // 2 different files with the same name
                    $nameInfo = pathinfo($document->name);

                    for($i = 1;; $i++){
                        $name = $nameInfo['filename'].' ('.$i.').'.$nameInfo['extension'];

                        if(empty($toZip[$name])){
                            $toZip[$name] = $document;
                            $size += $document->size;
                            break;
                        } else if ($toZip[$name]->hash == $document->hash){
                            // We're not adding this after all
                            break;
                        }
                    }

                }
            }
            else{
                $toZip[$document->name] = $document;
                $size += $document->size;
            }
        }

        return $toZip;
    }

    public function getInvoiceDocumentsZip($invitationKey){
        if (!$invitation = $this->invoiceRepo->findInvoiceByInvitation($invitationKey)) {
            return $this->returnError();
        }

        Session::put('contact_key', $invitation->contact->contact_key);// track current contact

        $invoice = $invitation->invoice;

        $toZip = $this->getInvoiceZipDocuments($invoice);

        if(!count($toZip)){
            return Response::view('error', array('error'=>'No documents small enough'), 404);
        }

        $zip = new ZipArchive($invitation->account->name.' Invoice '.$invoice->invoice_number.'.zip');
        return Response::stream(function() use ($toZip, $zip) {
            foreach($toZip as $name=>$document){
                $fileStream = $document->getStream();
                if($fileStream){
                    $zip->init_file_stream_transfer($name, $document->size, array('time'=>$document->created_at->timestamp));
                    while ($buffer = fread($fileStream, 256000))$zip->stream_file_part($buffer);
                    fclose($fileStream);
                    $zip->complete_file_stream();
                }
                else{
                    $zip->add_file($name, $document->getRaw());
                }
            }
            $zip->finish();
        }, 200);
    }

    public function getDocument($invitationKey, $publicId){
        if (!$invitation = $this->invoiceRepo->findInvoiceByInvitation($invitationKey)) {
            return $this->returnError();
        }

        Session::put('contact_key', $invitation->contact->contact_key);// track current contact

        $clientId = $invitation->invoice->client_id;
        $document = Document::scope($publicId, $invitation->account_id)->firstOrFail();

        $authorized = false;
        if($document->expense && $document->expense->client_id == $invitation->invoice->client_id){
            $authorized = true;
        } else if($document->invoice && $document->invoice->client_id == $invitation->invoice->client_id){
            $authorized = true;
        }

        if(!$authorized){
            return Response::view('error', array('error'=>'Not authorized'), 403);
        }

        return DocumentController::getDownloadResponse($document);
    }

    public function paymentMethods()
    {
        if (!$contact = $this->getContact()) {
            return $this->returnError();
        }

        $client = $contact->client;
        $account = $client->account;

        $paymentDriver = $account->paymentDriver(false, GATEWAY_TYPE_TOKEN);
        $customer = $paymentDriver->customer($client->id);

        $data = array(
            'account' => $account,
            'contact' => $contact,
            'color' => $account->primary_color ? $account->primary_color : '#0b4d78',
            'client' => $client,
            'clientViewCSS' => $account->clientViewCSS(),
            'clientFontUrl' => $account->getFontsUrl(),
            'paymentMethods' => $customer ? $customer->payment_methods : false,
            'gateway' => $account->getTokenGateway(),
            'title' => trans('texts.payment_methods'),
            'transactionToken' => $paymentDriver->createTransactionToken(),
        );

        return response()->view('payments.paymentmethods', $data);
    }

    public function verifyPaymentMethod()
    {
        $publicId = Input::get('source_id');
        $amount1 = Input::get('verification1');
        $amount2 = Input::get('verification2');

        if (!$contact = $this->getContact()) {
            return $this->returnError();
        }

        $client = $contact->client;
        $account = $client->account;

        $paymentDriver = $account->paymentDriver(null, GATEWAY_TYPE_BANK_TRANSFER);
        $result = $paymentDriver->verifyBankAccount($client, $publicId, $amount1, $amount2);

        if (is_string($result)) {
            Session::flash('error', $result);
        } else {
            Session::flash('message', trans('texts.payment_method_verified'));
        }

        return redirect()->to($account->enable_client_portal_dashboard?'/client/dashboard':'/client/payment_methods/');
    }

    public function removePaymentMethod($publicId)
    {
        if (!$contact = $this->getContact()) {
            return $this->returnError();
        }

        $client = $contact->client;
        $account = $contact->account;

        $paymentDriver = $account->paymentDriver(false, GATEWAY_TYPE_TOKEN);
        $paymentMethod = PaymentMethod::clientId($client->id)
            ->wherePublicId($publicId)
            ->firstOrFail();

        try {
            $paymentDriver->removePaymentMethod($paymentMethod);
            Session::flash('message', trans('texts.payment_method_removed'));
        } catch (Exception $exception) {
            Session::flash('error', $exception->getMessage());
        }

        return redirect()->to($client->account->enable_client_portal_dashboard?'/client/dashboard':'/client/payment_methods/');
    }

    public function setDefaultPaymentMethod(){
        if (!$contact = $this->getContact()) {
            return $this->returnError();
        }

        $client = $contact->client;
        $account = $client->account;

        $validator = Validator::make(Input::all(), array('source' => 'required'));
        if ($validator->fails()) {
            return Redirect::to($client->account->enable_client_portal_dashboard?'/client/dashboard':'/client/payment_methods/');
        }

        $paymentDriver = $account->paymentDriver(false, GATEWAY_TYPE_TOKEN);
        $paymentMethod = PaymentMethod::clientId($client->id)
            ->wherePublicId(Input::get('source'))
            ->firstOrFail();

        $customer = $paymentDriver->customer($client->id);
        $customer->default_payment_method_id = $paymentMethod->id;
        $customer->save();

        Session::flash('message', trans('texts.payment_method_set_as_default'));

        return redirect()->to($client->account->enable_client_portal_dashboard?'/client/dashboard':'/client/payment_methods/');
    }

    private function paymentMethodError($type, $error, $accountGateway = false, $exception = false)
    {
        $message = '';
        if ($accountGateway && $accountGateway->gateway) {
            $message = $accountGateway->gateway->name . ': ';
        }
        $message .= $error ?: trans('texts.payment_method_error');

        Session::flash('error', $message);
        Utils::logError("Payment Method Error [{$type}]: " . ($exception ? Utils::getErrorString($exception) : $message), 'PHP', true);
    }

    public function setAutoBill(){
        if (!$contact = $this->getContact()) {
            return $this->returnError();
        }

        $client = $contact->client;

        $validator = Validator::make(Input::all(), array('public_id' => 'required'));

        if ($validator->fails()) {
            return Redirect::to('client/invoices/recurring');
        }

        $publicId = Input::get('public_id');
        $enable = Input::get('enable');
        $invoice = $client->invoices->where('public_id', intval($publicId))->first();

        if ($invoice && $invoice->is_recurring && ($invoice->auto_bill == AUTO_BILL_OPT_IN || $invoice->auto_bill == AUTO_BILL_OPT_OUT)) {
            $invoice->client_enable_auto_bill = $enable ? true : false;
            $invoice->save();
        }

        return Redirect::to('client/invoices/recurring');
    }
}