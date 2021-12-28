<?php

namespace App\Http\Controllers;

use App\Models\Assignments;
use App\Models\GradingCriteria;
use App\Models\Questions;
use App\Models\QuestionTestCases;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class QuestionController extends Controller
{
    private $grading_criterias = [
        'compiling',
        'styling',
        // 'logic',
        'not_hidden_test_cases',
        // 'hidden_test_cases',
        // 'features',
    ];
    public function add($id){

        $assignment = Assignments::find($id);
        return view('instructor.add-question',["assignment"=>$assignment,'grading_criterias'=>$this->grading_criterias]);
    }
    public function store(Request $request){
        $request->validate([
            'assignment_id'=>"required|exists:assignments,id",
            'name'=>"required|string",
            'description' => "required|string",
            'grade' => "required|numeric",
            'input' => "nullable|array",
            'output' => "nullable|array",
        ]);
        $total_grading_criteria=0;
        foreach($this->grading_criterias as $grading_criteria){
            $total_grading_criteria += $request[$grading_criteria];
        }
        if($total_grading_criteria != 100){
            return redirect()->back()->with('error','Total grading criteria percentage must be "100%"')->withInput();
        }
        $question = new Questions();
        $question->name = $request->name;
        $question->assignment_id = $request->assignment_id;
        $question->description = $request->description;
        $question->grade = $request->grade;
        $question->save();
        $i = 0;
        foreach($request->input as $input){
            if($i>0){
                $question_test_case = new QuestionTestCases();
                $question_test_case->inputs = $input;
                $question_test_case->output = $request->output[$i];
                $question_test_case->question_id = $question->id;
                $question_test_case->save();
            }
            $i++;
        }
        if(count($this->grading_criterias)>0){
            $grading_criteria_record = new GradingCriteria();
            foreach ($this->grading_criterias as $grading_criteria) {
                $grading_criteria_record["${grading_criteria}_weight"] = $request["${grading_criteria}"];
            }
            $grading_criteria_record->question_id = $question->id;
            $grading_criteria_record->save();
        }
        return redirect()->back()->with('success',"Question added successfully");
    }

    public function student_submit(Request $request){
        $request->validate([
            'question_id'=>'required|exists:questions,id',
            'submission'=>'file|required'
        ]);
        
        $total_grade = 0;

        $question = Questions::with(['assignment','submissions', 'test_cases', 'grading_criteria'])->find($request->question_id);
        $submission = new Submission();

        //Saving Submission
        $extension = $request->file('submission')->getClientOriginalExtension();
        $fileNameToStore = $request->name . '-' . time() . '.' . $extension;
        $submission_number = count($question->submissions)+1;
        $user_name = Auth::user()->name ;
        $assignment_submission_path = "/assignment_submissions/{$question->assignment->name}/{$question->name}/$user_name/$submission_number";
        $request->submission->move(public_path($assignment_submission_path), $fileNameToStore);
        $submission->submitted_code = $assignment_submission_path.'/'. $fileNameToStore;
        $submission->question_id = $question->id;

        //Compiling Submitted file
        $cpp_executable = env('CPP_EXE_PATH');
        $output_1 = shell_exec("$cpp_executable \"" . public_path($submission->submitted_code) . "\" -o \"" . public_path($assignment_submission_path) . "/output\" 2>&1");
        
        //Saving compiler error if exists
        if($output_1 != null || strlen($output_1)>0) {
            $output_1 = str_replace(public_path($submission->submitted_code),'',$output_1);
            $submission->compile_feedback = $output_1;
        }

        //If no compiler error (The output file won't exist unless no errors found)
        if(file_exists($_SERVER['DOCUMENT_ROOT']. $assignment_submission_path. "/output.exe")){
            // Give grade for compiling if the criteria exists
            if($question->grading_criteria->last()){
                if($question->grading_criteria->last()->compiling_weight){
                    //Grade = Grading_percentage/100 * Total_Grade
                    $submission->compiling_grade += $question->grading_criteria->last()->compiling_weight/100 * $question->grade;
                    $total_grade += $submission->compiling_grade;
                }
            }
        }

        //Running Test Cases
        $number_of_test_cases_passed=0;
        $total_excectution_times = 0;
        foreach ($question->test_cases as $test_case){
            //Calculating runtime of the submitted program
            $start_time = microtime(true);
            $test_case_file = public_path($assignment_submission_path . "/test_case_".$test_case->id);
            file_put_contents($test_case_file, $test_case->inputs);
            $output = shell_exec("\"". public_path($assignment_submission_path) . "/output\" < \"". $test_case_file."\"");
            if($output == $test_case->output) {
                $number_of_test_cases_passed +=1; 
            }
            $submission->meta .= "\n".$output;
            $end_time = microtime(true);
            $execution_time = ($end_time - $start_time);
            $execution_time = number_format((float)$execution_time, 4, '.', '');
            $total_excectution_times+= $execution_time;
        }
        if(count($question->test_cases)>0){
            $submission->execution_time = $total_excectution_times / count($question->test_cases);
        }else{
            $submission->execution_time = NULL;
        }
        $number_of_test_cases = count($question->test_cases);

        $submission->logic_feedback = "Number of Test Cases Passed: $number_of_test_cases_passed/$number_of_test_cases";

        if ($question->grading_criteria->last() && $number_of_test_cases>0) {
            if ($question->grading_criteria->last()->not_hidden_test_cases_weight) {
                //Give grade for Test Cases Passed:
                //Test_Cases_Grade = Passed_Test_Cases/Total_Test_Cases * Grading_Percentage_for_Test_Cases
                //Test_Cases_Grade_Total = Test_Cases_Grade/100 * Total_Grade
                $total_test_cases_grade = ($number_of_test_cases_passed / $number_of_test_cases) * $question->grading_criteria->last()->not_hidden_test_cases_weight;
                $total_test_cases_grade_total = $total_test_cases_grade / 100 * $question->grade;
                $submission->not_hidden_logic_grade = $total_test_cases_grade_total;
                $total_grade += $submission->not_hidden_logic_grade;
            }
        }
      
        $submission->total_grade = $total_grade;
        $submission->save();
        
        return redirect()->back()->with('question_'.$request->question_id,"Answer Submitted for {$question->name}");
    }
}
