<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamRecord;
use App\Models\ScoreAppeal;
use App\Models\ScoreAppealLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ScoreAppealController extends Controller
{
    public function myAppeals(Request $request)
    {
        $user = $request->user();

        $appeals = ScoreAppeal::with(['examRecord.examPaper', 'question', 'handler'])
            ->where('student_id', $user->id)
            ->orderBy('id', 'desc')
            ->paginate($perPage = $request->input('per_page', 15));

        $appeals->getCollection()->transform(function ($appeal) {
            return $this->formatAppeal($appeal);
        });

        return response()->json([
            'appeals' => $appeals,
        ]);
    }

    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$user->isTeacher()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $query = ScoreAppeal::with(['examRecord.examPaper', 'student', 'question', 'handler']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('appeal_type')) {
            $query->where('appeal_type', $request->appeal_type);
        }

        if ($request->filled('exam_paper_id')) {
            $query->whereHas('examRecord', function ($q) use ($request) {
                $q->where('exam_paper_id', $request->exam_paper_id);
            });
        }

        $appeals = $query->orderBy('id', 'desc')
            ->paginate($perPage = $request->input('per_page', 15));

        $appeals->getCollection()->transform(function ($appeal) {
            return $this->formatAppeal($appeal);
        });

        return response()->json([
            'appeals' => $appeals,
        ]);
    }

    public function show(Request $request, ScoreAppeal $appeal)
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$user->isTeacher() && $appeal->student_id !== $user->id) {
            return response()->json(['message' => '无权查看此申诉'], 403);
        }

        $appeal->load([
            'examRecord.examPaper.questions',
            'examRecord.answers.question',
            'student',
            'question',
            'handler',
            'logs.handler',
        ]);

        $data = $this->formatAppeal($appeal);
        $data['logs'] = $appeal->logs->map(function ($log) {
            return [
                'id' => $log->id,
                'action' => $log->action,
                'action_text' => ScoreAppeal::ACTIONS[$log->action] ?? $log->action,
                'score_adjustment' => $log->score_adjustment,
                'opinion' => $log->opinion,
                'from_status' => $log->from_status,
                'from_status_text' => ScoreAppeal::STATUSES[$log->from_status] ?? $log->from_status,
                'to_status' => $log->to_status,
                'to_status_text' => ScoreAppeal::STATUSES[$log->to_status] ?? $log->to_status,
                'handler' => $log->handler ? [
                    'id' => $log->handler->id,
                    'username' => $log->handler->username,
                    'real_name' => $log->handler->real_name,
                    'role' => $log->handler->role,
                ] : null,
                'created_at' => $log->created_at,
            ];
        });

        if ($user->isStudent() && $appeal->student_id === $user->id) {
            unset($data['teacher_opinion']);
        }

        return response()->json([
            'appeal' => $data,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (!$user->isStudent()) {
            return response()->json(['message' => '只有学生可以提交申诉'], 403);
        }

        $validator = Validator::make($request->all(), [
            'exam_record_id' => 'required|exists:exam_records,id',
            'question_id' => 'nullable|exists:questions,id',
            'appeal_type' => 'required|in:score,grading,abnormal',
            'reason' => 'required|string|min:5|max:2000',
            'evidence' => 'nullable|array',
            'evidence.*' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $examRecord = ExamRecord::where('id', $request->exam_record_id)
            ->where('user_id', $user->id)
            ->first();

        if (!$examRecord) {
            return response()->json(['message' => '考试记录不存在'], 404);
        }

        if ($examRecord->status !== ExamRecord::STATUS_GRADED) {
            return response()->json(['message' => '只能对已评分的考试提交申诉'], 422);
        }

        $existingPending = ScoreAppeal::where('exam_record_id', $examRecord->id)
            ->where('student_id', $user->id)
            ->whereIn('status', [ScoreAppeal::STATUS_PENDING, ScoreAppeal::STATUS_REVIEWING])
            ->exists();

        if ($existingPending) {
            return response()->json(['message' => '该考试已有正在处理的申诉，请等待处理完成'], 422);
        }

        if ($request->filled('question_id')) {
            $questionExists = $examRecord->answers()
                ->where('question_id', $request->question_id)
                ->exists();
            if (!$questionExists) {
                return response()->json(['message' => '该题目不属于此考试'], 422);
            }
        }

        $appeal = DB::transaction(function () use ($request, $user, $examRecord) {
            $appeal = ScoreAppeal::create([
                'exam_record_id' => $examRecord->id,
                'student_id' => $user->id,
                'question_id' => $request->question_id,
                'appeal_type' => $request->appeal_type,
                'reason' => $request->reason,
                'evidence' => $request->evidence,
                'status' => ScoreAppeal::STATUS_PENDING,
                'original_score' => $examRecord->score,
            ]);

            ScoreAppealLog::create([
                'appeal_id' => $appeal->id,
                'handler_id' => $user->id,
                'action' => 'submit',
                'score_adjustment' => 0,
                'opinion' => '学生提交申诉：' . $request->reason,
                'from_status' => '',
                'to_status' => ScoreAppeal::STATUS_PENDING,
            ]);

            return $appeal;
        });

        $appeal->load(['examRecord.examPaper', 'question']);

        return response()->json([
            'message' => '申诉提交成功',
            'appeal' => $this->formatAppeal($appeal),
        ], 201);
    }

    public function review(Request $request, ScoreAppeal $appeal)
    {
        $user = $request->user();

        if (!$user->isAdmin() && !$user->isTeacher()) {
            return response()->json(['message' => '无权处理申诉'], 403);
        }

        if ($appeal->isClosed()) {
            return response()->json(['message' => '该申诉已完成处理，无法再次操作'], 422);
        }

        $validator = Validator::make($request->all(), [
            'action' => 'required|in:maintain,add_score,deduct_score,transfer,close',
            'opinion' => 'required|string|min:2|max:2000',
            'score_adjustment' => 'nullable|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $action = $request->action;
        $scoreAdjustment = (float) $request->input('score_adjustment', 0);

        if (in_array($action, [ScoreAppeal::ACTION_ADD_SCORE, ScoreAppeal::ACTION_DEDUCT_SCORE]) && $scoreAdjustment <= 0) {
            return response()->json(['message' => '加减分操作必须填写大于0的分数'], 422);
        }

        $fromStatus = $appeal->status;

        $result = DB::transaction(function () use ($appeal, $user, $action, $scoreAdjustment, $request, $fromStatus) {
            $toStatus = $this->mapActionToStatus($action);
            $finalScore = $appeal->original_score;

            if ($action === ScoreAppeal::ACTION_ADD_SCORE) {
                $finalScore = $appeal->original_score + $scoreAdjustment;
            } elseif ($action === ScoreAppeal::ACTION_DEDUCT_SCORE) {
                $finalScore = max(0, $appeal->original_score - $scoreAdjustment);
                $scoreAdjustment = -$scoreAdjustment;
            }

            $appeal->update([
                'status' => $toStatus,
                'final_score' => $finalScore,
                'teacher_opinion' => $request->opinion,
                'handled_by' => $user->id,
                'handled_at' => now(),
            ]);

            if (in_array($action, [ScoreAppeal::ACTION_ADD_SCORE, ScoreAppeal::ACTION_DEDUCT_SCORE])) {
                $appeal->examRecord()->update([
                    'score' => $finalScore,
                ]);
            }

            ScoreAppealLog::create([
                'appeal_id' => $appeal->id,
                'handler_id' => $user->id,
                'action' => $action,
                'score_adjustment' => $scoreAdjustment,
                'opinion' => $request->opinion,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
            ]);

            return $appeal;
        });

        $result->load(['examRecord.examPaper', 'student', 'question', 'handler', 'logs.handler']);

        return response()->json([
            'message' => '申诉处理成功',
            'appeal' => $this->formatAppeal($result),
        ]);
    }

    public function getTypes()
    {
        return response()->json([
            'appeal_types' => ScoreAppeal::APPEAL_TYPES,
            'statuses' => ScoreAppeal::STATUSES,
            'actions' => ScoreAppeal::ACTIONS,
        ]);
    }

    protected function mapActionToStatus(string $action): string
    {
        return match ($action) {
            ScoreAppeal::ACTION_MAINTAIN => ScoreAppeal::STATUS_MAINTAINED,
            ScoreAppeal::ACTION_ADD_SCORE, ScoreAppeal::ACTION_DEDUCT_SCORE => ScoreAppeal::STATUS_SCORE_UPDATED,
            ScoreAppeal::ACTION_TRANSFER => ScoreAppeal::STATUS_TRANSFERRED,
            ScoreAppeal::ACTION_CLOSE => ScoreAppeal::STATUS_CLOSED,
            default => ScoreAppeal::STATUS_PENDING,
        };
    }

    protected function formatAppeal(ScoreAppeal $appeal): array
    {
        $data = [
            'id' => $appeal->id,
            'exam_record_id' => $appeal->exam_record_id,
            'student_id' => $appeal->student_id,
            'question_id' => $appeal->question_id,
            'appeal_type' => $appeal->appeal_type,
            'appeal_type_text' => ScoreAppeal::APPEAL_TYPES[$appeal->appeal_type] ?? $appeal->appeal_type,
            'reason' => $appeal->reason,
            'evidence' => $appeal->evidence,
            'status' => $appeal->status,
            'status_text' => ScoreAppeal::STATUSES[$appeal->status] ?? $appeal->status,
            'original_score' => $appeal->original_score,
            'final_score' => $appeal->final_score,
            'teacher_opinion' => $appeal->teacher_opinion,
            'handled_at' => $appeal->handled_at,
            'created_at' => $appeal->created_at,
            'updated_at' => $appeal->updated_at,
            'is_closed' => $appeal->isClosed(),
        ];

        if ($appeal->relationLoaded('examRecord')) {
            $data['exam_record'] = [
                'id' => $appeal->examRecord->id,
                'score' => $appeal->examRecord->score,
                'status' => $appeal->examRecord->status,
                'user_id' => $appeal->examRecord->user_id,
                'exam_paper' => $appeal->examRecord->examPaper ? [
                    'id' => $appeal->examRecord->examPaper->id,
                    'title' => $appeal->examRecord->examPaper->title,
                ] : null,
            ];
        }

        if ($appeal->relationLoaded('student')) {
            $data['student'] = [
                'id' => $appeal->student->id,
                'username' => $appeal->student->username,
                'real_name' => $appeal->student->real_name,
            ];
        }

        if ($appeal->relationLoaded('question')) {
            $data['question'] = $appeal->question ? [
                'id' => $appeal->question->id,
                'type' => $appeal->question->type,
                'title' => $appeal->question->title,
                'score' => $appeal->question->score,
            ] : null;
        }

        if ($appeal->relationLoaded('handler') && $appeal->handler) {
            $data['handler'] = [
                'id' => $appeal->handler->id,
                'username' => $appeal->handler->username,
                'real_name' => $appeal->handler->real_name,
                'role' => $appeal->handler->role,
            ];
        }

        return $data;
    }
}
