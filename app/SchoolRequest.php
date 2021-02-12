<?php

namespace App;

use App\Traits\Filterable;
use Illuminate\Database\Eloquent\Model;

class SchoolRequest extends Model
{
    use Filterable;

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function grade()
    {
        return $this->belongsTo(SchoolGrade::class);
    }

    public function pricePackage()
    {
        return $this->belongsTo(PricePackage::class);
    }

    public function scopeWhereZone($query, $args) 
    {
        if (array_key_exists('zone_id', $args) && $args['zone_id']) {
            $query = $query->whereHas('school', function($query) use ($args) {
                $query->whereIn('zone_id', $args['zone_id']);
            });
        }

        return $query->where('status', 'PENDING')
            ->limit($args['limit'])
            ->latest('created_at');
    }

    public function scopeWhereArchived($query, array $args)
    {
        if (array_key_exists('period', $args) && $args['period']) {
            $query = $this->dateFilter($args['period'], $query, 'created_at');
        }

        return $query->where('status', '<>', 'PENDING')
            ->latest('created_at');
    }

    public static function accept($ids)
    {
        return self::whereIn('id', $ids)
            ->update(['status' => 'ACCEPTED']);
    }

    public static function restore($id)
    {
        if (is_array($id)) {
            $query = self::whereIn('id', $id);
        } else {
            $query = self::where('id', $id);
        }

        return $query->update(['status' => 'PENDING']);
    }

    public static function reject($id, $response)
    {
        if (is_array($id)) {
            $query = self::whereIn('id', $id);
        } else {
            $query = self::where('id', $id);
        }

        return $query->update(['status' => 'REJECTED', 'response' => $response]);
    }
}
