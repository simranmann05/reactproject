<?php
namespace App\Http\Controllers\Backend;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Spatie\Permission\Models\Role;
use App\Project;
use App\ProductTask;
use App\Helpers\Helper;
use App\ProductSubTask;
use App\ProjectMilestones;
use App\ProjectMessage;
use App\ProjectFile;
use App\projectBilling;
use App\ProjectNoteBook;
use App\ProjectRisk; 
use App\ProjectComment;
use App\ProjectBillingInvoices;
use App\ProjectUserRates;
use App\ProjectAssignedUser;
use App\ProjectLogTime;
use App\ProjectTaskAssignedUser;
use DB;
use PDF;
use Carbon\Carbon;
use Validator;
use Session;
use Hash;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Support\Facades\Auth;
 

class ProjectBillingController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index($projectId)
    {
        $items  = Project::where('id',$projectId)->first();		
        $section_name = $items ? $items->project_name : 'Project overview';		
        $section_task = 'Billing';        		
        $jinns_data = Helper::get_assigned_jinns_by_project_id($projectId);
        $invoices = ProjectBillingInvoices::with('user', 'project')->where('project_id', $projectId)->orderBy("id", "desc")->get();
        //get rates
        $jinnsRates = [];
        $userD = [];
        if( $jinns_data && isset($jinns_data->podjinn) ){
            $userD['id'] = $jinns_data->podjinn->id;
            $userD['name'] = $jinns_data->podjinn->name;
            $userD['rates'] = '';
            $check_rate = ProjectUserRates::where('user_id', $jinns_data->podjinn->id)->where('project_id', $projectId)->first();            
            if( $check_rate ){
                $userD['rates'] = $check_rate->rate;
            }
            $jinnsRates[] = $userD;
			if( $jinns_data->jinns ){
            foreach( $jinns_data->jinns as $ind=>$jinn  ){
                 $userD['id'] = $jinn->id;
                $userD['name'] = $jinn->name;
                $userD['rates'] = '';
                $check_rate_j = ProjectUserRates::where('user_id', $jinn->id)->where('project_id', $projectId)->first();
                if( $check_rate_j ){
                    $userD['rates'] = $check_rate_j->rate;
                }
                $jinnsRates[] = $userD;              
            }
            }
        }   
        return view('backend/project/billing/project_billing_overview',compact('items','jinnsRates', 'invoices', 'jinns_data','section_name','section_task'));
    }

    //getBillingData
    public function getBillingData(Request $request){
        $user = Auth::user();
        /*$chk_premission = $user->hasAnyPermission(['podjinn_create']);
        if (!$chk_premission) {
            return response()->json([
            "status" => false,
            "msg" => "access denied"
        ]);
        }*/

        $validator = Validator::make($request->all(), [
            'project_id' => 'required',
            'user_id' => 'required',
        ]);

        if (!$validator->passes()) {
            return response()->json([
                "status" => false,
                "msg" => "Validation errors",
                "error" => $validator->errors()->all()
            ]);
        }

        //filter 
        $billable = $request->billable ? 1 : 0;
        $start_date = $request->start_date ? $request->start_date : date("Y-m-d");
        $end_date = $request->end_date ? $request->end_date : date("Y-m-d");
        $order_key = $request->sort_by ? $request->sort_by : 'id';
        $sort_order = $request->order_by ? $request->order_by : 'desc';

        $log_time_data = ProjectLogTime::with('project', 'user', 'task')
                        ->where(['project_id' => $request->project_id, 'user_id' => $request->user_id, 'billable' => $billable, 'is_time_logged' => 1])
                        ->where('start_date', '>=', $start_date . ' 00:00:00')
                        ->where('end_date_time', '<=', $end_date . ' 23:59:59')                        
                        ->orderBy($order_key, $sort_order)                        
                        ->get();

            if( count($log_time_data) > 0 ){
                foreach( $log_time_data as $time ){
                    $user_rate = ProjectUserRates::where("user_id", $time->user_id)->pluck('rate')->first();                   
                    if( !$user_rate ){
                        $user_rate = $time->project ? $time->project->default_user_rate_per_hour : 0;
                    }
                    $time->user_rate_per_hour = $user_rate;
                }
            }

            // echo count($log_time_data);
            //dd( $log_time_data );

        $content = view('backend/project/billing/billing_data_response', compact('log_time_data'))->render();
        if ($content) {               
            return response()->json([
                "status" => true,
                "msg" => "Success",
                "html" => $content
            ]);
        }

        return response()->json([
            "status" => false,                
            "msg" => "Invalid data.",
        ]);
         
    }


    //generateInvoiceData
    public function generateInvoiceData(Request $request){
        $user = Auth::user();
        /*$chk_premission = $user->hasAnyPermission(['podjinn_create']);
        if (!$chk_premission) {
            return response()->json([
            "status" => false,
            "msg" => "access denied"
        ]);
        }*/

        if( !$request->ids && !$request->due_date && !$request->start_date ){
            return response()->json([
                "status" => false,                
                "msg" => "Invalid data.",
            ]);
        }
        $billing_start_date = $request->start_date;
        $billing_due_date = $request->due_date;
        //filter         
        $log_time_data = ProjectLogTime::with('project', 'user', 'task')
                        ->whereIn('id', $request->ids)
                        ->where(['is_time_logged' => 1])                        
                        ->orderBy('id', 'desc')                        
                        ->get();

            if (count($log_time_data) > 0) {
                foreach ($log_time_data as $time) {
                    $user_rate = ProjectUserRates::where("user_id", $time->user_id)->pluck('rate')->first();
                    if (!$user_rate) {
                        $user_rate = $time->project ? $time->project->default_user_rate_per_hour : 0;
                    }
                    $time->user_rate_per_hour = $user_rate;
                }

                //echo count($log_time_data);
                //dd( $request->ids );
                $project = $log_time_data[0]->project;
                $user_data = $log_time_data[0]->user;
                $user_rate_per_hour = $log_time_data[0]->user_rate_per_hour;

                $content = view('backend/project/billing/billing_invoice_modal', compact('log_time_data','billing_start_date', 'billing_due_date', 'user_rate_per_hour', 'project', 'user_data'))->render();
                if ($content) {
                    return response()->json([
                        "status" => true,
                        "msg" => "Success",
                        "html" => $content
                    ]);
                }
            }else{
                return response()->json([
                    "status" => false,                
                    "msg" => "No data found.",
                ]);
            }

        return response()->json([
            "status" => false,                
            "msg" => "Invalid data.",
        ]);
         
    }


    //saveInvoiceData
    public function saveInvoiceData(Request $request){    
        $user = Auth::user();
        /*$chk_premission = $user->hasAnyPermission(['podjinn_create']);
        if (!$chk_premission) {
            return response()->json([
            "status" => false,
            "msg" => "access denied"
        ]);
        }*/

        $validator = Validator::make($request->all(), [
            'project_id' => 'required',
            'billing_from' => 'required',
            'billing_to' => 'required',
            'logtime' => 'required',
            'user_id' => 'required',
            'log_time_ids' => 'required',
          ]);

        if (!$validator->passes()) {
            return response()->json([
                "status" => false,
                "msg" => "Validation errors",
                "error" => $validator->errors()->all()
            ]);
        }

        //generate invoice id
        $billingInvoice = ProjectBillingInvoices::orderBy('id', 'desc')->first();
        if( $billingInvoice ){
            $invoiceID = $billingInvoice->invoice_id;
            $invoiceExplode = explode("-",$invoiceID);
            $invoiceName = $invoiceExplode[1]+1;            
            $invoice_id = $invoiceExplode[0]."-".$invoiceName;
        }else{
            $invoice_id = "INVOICE-1";
        }

        //ProjectBillingInvoices
        $ins = ProjectBillingInvoices::create([
            'invoice_id' => $invoice_id,
            'project_id' => $request->project_id,
            'user_id' => $request->user_id,
            'user_rate_per_hour' => $request->user_rate_per_hour,
            'billing_from' => $request->billing_from,
            'billing_to' => $request->billing_to,
            'grand_total' => $request->grand_total,
            'log_time_ids' => $request->log_time_ids,
            'task_data' => serialize($request->logtime),
            'created_by' => $user->id,
        ]);

        
        //return redirect('admin/project/53/billing');

        //return $pdf->stream();
        if( $ins ){
            return response()->json([
                "status" => true,                
                "id" => $ins->id,                
                "msg" => "Invoice generated successfully.",
            ]);
        }else{
            return response()->json([
                "status" => false,                
                "msg" => "Invoice not generated. Please try again",
            ]);
        }
    }

    public function download_pdf(Request $request, $id){
        $user = Auth::user();
        /*$chk_premission = $user->hasAnyPermission(['podjinn_create']);
        if (!$chk_premission) {
            return response()->json([
            "status" => false,
            "msg" => "access denied"
        ]);
        }*/
        if( $id ){
            $data = [];
            //ins
            $data['invoice'] = ProjectBillingInvoices::with("project", "user")->where("id", $request->id)->first();            
            $pdf = PDF::loadView('backend/project/billing/billing_pdf', $data);
            return $pdf->download($data['invoice']->invoice_id . '.pdf');
        }

    }

    //saveUserRates     
    public function saveUserRates(Request $request){    
        $user = Auth::user();
        /*$chk_premission = $user->hasAnyPermission(['podjinn_create']);
        if (!$chk_premission) {
            return response()->json([
            "status" => false,
            "msg" => "access denied"
        ]);
        }*/

        if( !$request->default_rates_val ){
            return response()->json([
                "status" => false,                
                "msg" => "Default rates is required",
            ]);
        }

        $default_rates = $request->default_rates_val;
        $project_id = $request->project_id;
        $update_default = Project::where('id', $project_id)->update(['default_user_rate_per_hour' => $default_rates]);

        //update user rates        
        if( $request->rates && count($request->rates) > 0 ){
            foreach( $request->rates as $rates ){                
                $user_id = $rates['user_id'];
                $user_rates = $rates['user_rates'];                
                $data = ['rate' => $user_rates, 'user_id' => $user_id, 'project_id' => $project_id];
                $check_rate = ProjectUserRates::where('user_id', $user_id)->where('project_id', $project_id)->first();
                if( $check_rate ){
                    $data['updated_by'] = $user->id;
                    ProjectUserRates::where('id', $check_rate->id)->update($data);
                }else{
                    $data['created_by'] = $user->id;
                    ProjectUserRates::create($data);
                }
            }
        }
        return response()->json([
            "status" => true,                
            "msg" => "Rates updated successfully.",
        ]);
    }

    public function deleteBillingInvoice(Request $request)
    {
        if ($request->id) {
            $delete = ProjectBillingInvoices::where('id', $request->id)->delete();
            Session::flash('message', 'Link Deleted Successfully');
            return response()->json([
                'status'          => true,
                'msg'          => "Link Deleted Successfully."
            ]);
        }
        return response()->json([
        'status'          => false,
        'msg'          => "Invalid id",
    ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\projectBilling  $projectBilling
     * @return \Illuminate\Http\Response
     */
    public function show(projectBilling $projectBilling)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\projectBilling  $projectBilling
     * @return \Illuminate\Http\Response
     */
    public function edit(projectBilling $projectBilling)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\projectBilling  $projectBilling
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, projectBilling $projectBilling)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\projectBilling  $projectBilling
     * @return \Illuminate\Http\Response
     */
    public function destroy(projectBilling $projectBilling)
    {
        //
    }
}
