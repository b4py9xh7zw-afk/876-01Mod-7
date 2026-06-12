<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScoreAppeal extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_record_id',
        'student_id',
        'question_id',
        'appeal_type',
        'reason',
        'evidence',
        'status',
        'original_score',
        'final_score',
        'teacher_opinion',
        'handled_by',
        'handled_at',
    ];

    protected $casts = [
        'exam_record_id' => 'integer',
        'student_id' => 'integer',
        'question_id' => 'integer',
        'evidence' => 'array',
        'original_score' => 'decimal:2',
        'final_score' => 'decimal:2',
        'handled_by' => 'integer',
        'handled_at' => 'datetime',
    ];

    public const APPEAL_TYPE_SCORE = 'score';
    public const APPEAL_TYPE_GRADING = 'grading';
    public const APPEAL_TYPE_ABNORMAL = 'abnormal';

    public const APPEAL_TYPES = [
        self::APPEAL_TYPE_SCORE => '分数申诉',
        self::APPEAL_TYPE_GRADING => '判题申诉',
        self::APPEAL_TYPE_ABNORMAL => '异常标记申诉',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_REVIEWING = 'reviewing';
    public const STATUS_MAINTAINED = 'maintained';
    public const STATUS_SCORE_UPDATED = 'score_updated';
    public const STATUS_TRANSFERRED = 'transferred';
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_PENDING => '待处理',
        self::STATUS_REVIEWING => '复核中',
        self::STATUS_MAINTAINED => '维持原判',
        self::STATUS_SCORE_UPDATED => '成绩已调整',
        self::STATUS_TRANSFERRED => '已转教务',
        self::STATUS_CLOSED => '已关闭',
    ];

    public const ACTION_SUBMIT = 'submit';
    public const ACTION_MAINTAIN = 'maintain';
    public const ACTION_ADD_SCORE = 'add_score';
    public const ACTION_DEDUCT_SCORE = 'deduct_score';
    public const ACTION_TRANSFER = 'transfer';
    public const ACTION_CLOSE = 'close';

    public const ACTIONS = [
        self::ACTION_SUBMIT => '提交申诉',
        self::ACTION_MAINTAIN => '维持原判',
        self::ACTION_ADD_SCORE => '加分',
        self::ACTION_DEDUCT_SCORE => '减分',
        self::ACTION_TRANSFER => '转教务',
        self::ACTION_CLOSE => '关闭申诉',
    ];

    public function examRecord()
    {
        return $this->belongsTo(ExamRecord::class, 'exam_record_id');
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function question()
    {
        return $this->belongsTo(Question::class, 'question_id');
    }

    public function handler()
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function logs()
    {
        return $this->hasMany(ScoreAppealLog::class, 'appeal_id')->orderBy('created_at', 'desc');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isReviewing(): bool
    {
        return $this->status === self::STATUS_REVIEWING;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [
            self::STATUS_MAINTAINED,
            self::STATUS_SCORE_UPDATED,
            self::STATUS_TRANSFERRED,
            self::STATUS_CLOSED,
        ]);
    }
}
