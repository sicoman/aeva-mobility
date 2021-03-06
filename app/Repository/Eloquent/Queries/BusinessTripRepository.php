<?php

namespace App\Repository\Eloquent\Queries;   

use App\BusinessTrip;
use App\BusinessTripEvent;
use App\Repository\Eloquent\BaseRepository;
use App\Repository\Queries\BusinessTripRepositoryInterface;

class BusinessTripRepository extends BaseRepository implements BusinessTripRepositoryInterface
{

    public function __construct(BusinessTrip $model)
    {
        parent::__construct($model);
    }

    public function userSubscriptions(array $args)
    {
        $userSubscriptions = $this->model->join('business_trip_users', 'business_trips.id', '=', 'business_trip_users.trip_id')
            ->where('business_trip_users.user_id', $args['user_id'])
            ->whereNotNull('business_trip_users.subscription_verified_at')
            ->selectRaw(
                'business_trips.id, business_trips.name, business_trips.name_ar,
                business_trips.type, business_trip_users.due_date, 
                business_trip_users.payable, business_trip_users.id as subscription_id'
            )
            ->get();

        return $userSubscriptions;
    }

    public function userTrips(array $args)
    {
        $date = date('Y-m-d', strtotime($args['day']));

        $userTrips = $this->model->selectRaw(
            'business_trips.id, business_trips.name, business_trips.name_ar, business_trips.days,
            business_trip_attendance.date AS absence_date'
        )
        ->join('business_trip_users', 'business_trips.id', '=', 'business_trip_users.trip_id')
        ->where('business_trip_users.user_id', $args['user_id'])
        ->whereNotNull('business_trip_users.subscription_verified_at')
        ->whereRaw('? between start_date and end_date', [date('Y-m-d')])
        ->whereRaw('JSON_EXTRACT(business_trips.days, "$.'.$args['day'].'") <> CAST("null" AS JSON)')
        ->where(function ($query) use ($args) {
            $query->whereNull('business_trip_schedules.days')
                ->orWhere('business_trip_schedules.days->'.$args['day'], true);
        })
        ->leftJoin('business_trip_attendance', function ($join) use ($args, $date) {
            $join->on('business_trips.id', '=', 'business_trip_attendance.trip_id')
                ->where('business_trip_attendance.user_id', $args['user_id'])
                ->where('business_trip_attendance.is_absent', true)
                ->where('business_trip_attendance.date', $date);
        })
        ->leftJoin('business_trip_schedules', function ($join) use ($args) {
            $join->on('business_trips.id', '=', 'business_trip_schedules.trip_id')
                ->where('business_trip_schedules.user_id', $args['user_id']);
        })
        ->get();

        if ($userTrips->isEmpty()) return [];

        return $this->userSchedule($userTrips, $args['day']);
    }

    public function userLiveTrips(array $args)
    {
        $today = strtolower(date('l'));

        return $this->model->selectRaw('business_trips.id, business_trips.name, business_trips.name_ar')
            ->join('business_trip_users', 'business_trips.id', '=', 'business_trip_users.trip_id')
            ->where('business_trip_users.user_id', $args['user_id'])
            ->whereNotNull('log_id')
            ->whereRaw('JSON_EXTRACT(business_trips.days, "$.'.$today.'") <> CAST("null" AS JSON)')
            ->where(function ($query) use ($today) {
                $query->whereNull('business_trip_schedules.days')
                    ->orWhere('business_trip_schedules.days->'.$today, true);
            })
            ->leftJoin('business_trip_schedules', function ($join) use ($args) {
                $join->on('business_trips.id', '=', 'business_trip_schedules.trip_id')
                    ->where('business_trip_schedules.user_id', $args['user_id']);
            })
            ->get();
    }

    public function driverTrips(array $args)
    {
        $driverTrips = $this->model->select('id', 'name', 'name_ar', 'days')
            ->where('driver_id', $args['driver_id'])
            ->whereRaw('? between start_date and end_date', [date('Y-m-d')])
            ->whereRaw('JSON_EXTRACT(days, "$.'.$args['day'].'") <> CAST("null" AS JSON)')
            ->get();

        if ($driverTrips->isEmpty()) return [];

        return $this->driverSchedule($driverTrips, $args['day']);
    }

    public function driverLiveTrips(array $args)
    {
        $liveTrips = $this->model->select('id', 'name', 'name_ar')
            ->where('driver_id', $args['driver_id'])
            ->whereNotNull('log_id')
            ->get();

        return $liveTrips;
    }

    public function userHistory(array $args)
    {
        return BusinessTripEvent::selectRaw('
            business_trips.name AS trip_name,
            business_trip_events.*
        ')
        ->join('business_trip_users', 'business_trip_users.trip_id', '=', 'business_trip_events.trip_id')
        ->join('business_trips', 'business_trips.id', '=', 'business_trip_events.trip_id')
        ->where('user_id', $args['user_id'])
        ->latest('business_trip_events.created_at');        
    }

    protected function userSchedule($trips, $day) 
    {
        $dateTime = date('Y-m-d', strtotime($day));
        
        foreach($trips as $trip) {
            $trip->is_absent = $trip->absence_date === $dateTime;
            $trip->starts_at = $dateTime.' '.$trip->days[$day];
        }
        
        return $trips->sortBy('starts_at')->values();
    }

    protected function driverSchedule($trips, $day) 
    {
        $dateTime = date('Y-m-d', strtotime($day));
        
        foreach($trips as $trip) {
            $trip->starts_at = $dateTime.' '.$trip->days[$day];
        }
        
        return $trips->sortBy('starts_at')->values();
    }
}