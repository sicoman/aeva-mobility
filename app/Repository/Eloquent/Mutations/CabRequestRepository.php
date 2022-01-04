<?php

namespace App\Repository\Eloquent\Mutations;   

use App\Driver;
use App\Vehicle;
use App\CabRequest;

use App\Events\RideEnded;
use App\Events\RideStarted;
use App\Events\DriverArrived;
use App\Events\AcceptCabRequest;
use App\Events\CabRequestAccepted;
use App\Events\CabRequestCancelled;

use App\Jobs\SendPushNotification;
use App\Exceptions\CustomException;

use App\Traits\HandleDeviceTokens;
use App\Traits\HandleDriverAttributes;

use App\Repository\Eloquent\BaseRepository;
use App\Repository\Mutations\CabRequestRepositoryInterface;

use Illuminate\Support\Arr;
use Illuminate\Database\Eloquent\ModelNotFoundException;


class CabRequestRepository extends BaseRepository implements CabRequestRepositoryInterface
{
    use HandleDeviceTokens, HandleDriverAttributes;

    /**
    * CabRequest constructor.
    *
    * @param CabRequest
    */
    public function __construct(CabRequest $model)
    {
        parent::__construct($model);
    }

    public function schedule(array $args)
    {
        $input = Arr::except($args, ['directive', 'user_name', 'distance', 'total_eta']);
        $args['next_free_time'] = date('Y-m-d H:i:s', 
            strtotime('+'.$args['total_eta'].' seconds', strtotime($args['schedule_time'])));

        if (!$this->isTimeValidated($args)) {
            throw new CustomException(__('lang.schedule_request_failed'));
        }

        $payload = [
            'summary' => [
                'distance' => $args['distance']
            ],
            'scheduled' => [
                'at' => date("Y-m-d H:i:s"),
                'user_name' => $args['user_name']
            ]
        ];

        $input['history'] = $payload;
        $input['status'] = 'SCHEDULED';
        $input['next_free_time'] = $args['next_free_time'];

        return $this->model->create($input); 
    }

    public function search(array $args)
    {
        if (array_key_exists('id', $args) && $args['id']) {
            $request = $this->searchScheduledRequest($args);
        } else {
            $request = $this->searchNewRequest($args);
        }

        SendPushNotification::dispatch(
            $this->driversToken($request->drivers_ids),
            __('lang.accept_request'),
            ['view' => 'AcceptRequest', 'request_id' => $request->id]
        );

        $user['id'] = $request->user_id;
        $user['name'] = $request->user_name;

        broadcast(new AcceptCabRequest($request->drivers_ids, $user));
        
        return $request;
    }

    protected function searchScheduledRequest(array $args) 
    {
        $request = $this->findRequest($args['id']);

        if ( $request->status == 'COMPLETED' || $request->status == 'CANCELLED' ) {
            throw new CustomException(__('lang.search_request_failed'));
        }

        $driversIds = $this->checkPendingAndGetDrivers($request->toArray());

        $payload = [
            'searching' => [
                'at' => date("Y-m-d H:i:s"),
            ]
        ];

        $input['history'] = array_merge($request->history, $payload);
        $input['status'] = 'SEARCHING';

        $request = $this->updateRequest($request, $input);
        $request['drivers_ids'] = $driversIds;

        return $request;
    }

    protected function searchNewRequest(array $args) 
    {
        $input = Arr::except($args, ['directive', 'user_name', 'distance']);
        $driversIds = $this->checkPendingAndGetDrivers($args);

        $payload = [
            'summary' => [
                'distance' => $args['distance']
            ],
            'searching' => [
                'at' => date("Y-m-d H:i:s"),
                'user_name' => $args['user_name']
            ]
        ];

        $input['history'] = $payload;
        $input['status'] = 'SEARCHING';

        $request = $this->model->create($input);
        $request['drivers_ids'] = $driversIds;

        return $request;
    }

    protected function checkPendingAndGetDrivers(array $args)
    {
        $activeRequests = $this->model->wherePending($args['user_id'])->first();

        if($activeRequests) {
            throw new CustomException(__('lang.request_inprogress'));
        }

        $driversIds = $this->getNearestDrivers($args['s_lat'], $args['s_lng']);

        if ( !count($driversIds) ) {
            throw new CustomException(__('lang.no_available_drivers'));
        }

        return $driversIds;
    }

    public function accept(array $args)
    {
        $request = $this->findRequest($args['id']);
        
        if ( $request->status != 'SEARCHING' ) {
            throw new CustomException(__('lang.accept_request_failed'));
        }

        $carType = Vehicle::select('fixed', 'price')
            ->join('car_types', 'car_types.id', '=', 'vehicles.car_type_id')
            ->find($args['vehicle_id']);

        $payload = [
            'accepted' => [
                'at' => date("Y-m-d H:i:s"),
            ]
        ];

        $args['history'] = array_merge($request->history, $payload);
        $args['costs'] = $carType['fixed'] + $carType['price'] * $request->history['summary']['distance'] / 1000;
        $args['status'] = 'ACCEPTED';

        $request = $this->updateRequest($request, $args);

        $this->updateDriverStatus($args['driver_id'] ,'RIDING');

        SendPushNotification::dispatch(
            $this->userToken($request->user_id),
            __('lang.request_accepted'),
            ['view' => 'RequestAccepted', 'request_id' => $request->id]
        );

        broadcast(new CabRequestAccepted($request->user_id, $args['driver_id']));

        return $request;
    }

    public function arrived(array $args)
    {
        $request = $this->findRequest($args['id']);
        
        if ( $request->status != 'ACCEPTED' ) {
            throw new CustomException(__('lang.update_request_status_failed'));
        }

        $payload = [
            'arrived' => [
                'at' => date("Y-m-d H:i:s"),
            ]
        ];

        $args['history'] = array_merge($request->history, $payload);
        $args['status'] = 'ARRIVED';

        $request = $this->updateRequest($request, $args);

        SendPushNotification::dispatch(
            $this->driverToken($request->driver_id),
            __('lang.start_ride'),
            ['view' => 'StartRide', 'request_id' => $request->id]
        );

        broadcast(new DriverArrived($request->driver_id, $request->user_id));

        return $request;
    }

    public function start(array $args)
    {
        $request = $this->findRequest($args['id']);
        
        if ( $request->status != 'ARRIVED' ) {
            throw new CustomException(__('lang.start_ride_failed'));
        }

        $payload = [
            'started' => [
                'at' => date("Y-m-d H:i:s"),
            ]
        ];

        $args['history'] = array_merge($request->history, $payload);
        $args['status'] = 'STARTED';

        $request = $this->updateRequest($request, $args);

        SendPushNotification::dispatch(
            $this->userToken($request->user_id),
            __('lang.ride_started'),
            ['view' => 'RideStarted', 'request_id' => $request->id]
        );

        broadcast(new RideStarted($request->user_id, $request->driver_id));

        return $request;
    }

    public function end(array $args)
    {
        $request = $this->findRequest($args['id']);
        
        if ( $request->status != 'STARTED' || $request->paid == false) {
            throw new CustomException(__('lang.end_ride_failed'));
        }

        $payload = [
            'completed' => [
                'at' => date("Y-m-d H:i:s"),
            ]
        ];

        $args['history'] = array_merge($request->history, $payload);
        $args['status'] = 'COMPLETED';

        $request = $this->updateRequest($request, $args);

        $this->updateDriverStatus($request->driver_id ,'ONLINE');

        SendPushNotification::dispatch(
            $this->userToken($request->user_id),
            __('lang.ride_ended'),
            ['view' => 'RideEnded', 'request_id' => $request->id]
        );

        broadcast(new RideEnded($request->user_id, $request->driver_id));

        return $request;
    }

    public function cancel(array $args)
    {
        $request = $this->findRequest($args['id']);
        
        if ( in_array($request->status, ['STARTED', 'COMPLETED']) ) {
            throw new CustomException(__('lang.cancel_cab_request_failed'));
        }

        $payload = [
            'cancelled' => [
                'at' => date("Y-m-d H:i:s"),
                'by' => $args['cancelled_by'],
                'reason' => array_key_exists('cancel_reason', $args) ? $args['cancel_reason'] : "",
            ]
        ];

        $args['history'] = array_merge($request->history, $payload);
        $args['status'] = 'CANCELLED';

        $request = $this->updateRequest($request, $args);

        $this->updateDriverStatus($request->driver_id ,'ONLINE');

        if ( strtolower($args['cancelled_by']) == 'user') {

            SendPushNotification::dispatch(
                $this->driverToken($request->driver_id),
                __('lang.request_cancelled'),
                ['view' => 'CancelRequest', 'request_id' => $request->id]
            );

            $data['request_id'] = $request->id;
            $data['user_id'] = $request->user_id;
            $data['driver_id'] = $request->driver_id;
            $data['cancel_reason'] = array_key_exists('cancel_reason', $args) ? $args['cancel_reason'] : "Unknown";

            broadcast(new CabRequestCancelled('user', $data));
        }

        if ( strtolower($args['cancelled_by']) == 'driver') {

            SendPushNotification::dispatch(
                $this->userToken($request->user_id),
                __('lang.request_cancelled'),
                ['view' => 'CancelRequest', 'request_id' => $request->id]
            );

            $data['request_id'] = $request->id;
            $data['user_id'] = $request->user_id;
            $data['driver_id'] = $request->driver_id;
            $data['cancel_reason'] = array_key_exists('cancel_reason', $args) ? $args['cancel_reason'] : "Unknown";

            broadcast(new CabRequestCancelled('driver', $data));        
        }

        return $request;
    }

    protected function updateRequest($request, $args) 
    {
        $input = Arr::except($args, ['id', 'directive', 'cancelled_by', 'cancel_reason']);

        $request->update($input);

        return $request;
    }

    protected function findRequest($id) 
    {
        try {
            return $this->model->findOrFail($id);
        } catch (ModelNotFoundException $e) {
            throw new \Exception(__('lang.request_not_found'));
        }
    }

    protected function getNearestDrivers($lat, $lng) 
    {
        $radius = config('custom.seats_search_radius');

        $driversIds = Driver::selectRaw('id ,
            ST_Distance_Sphere(point(longitude, latitude), point(?, ?))
            as distance
            ', [$lng, $lat]
            )
            ->having('distance', '<=', $radius)
            ->where('status', 'ACTIVE')
            ->orderBy('distance','asc')
            ->take(5)
            ->pluck('id')
            ->toArray();
        
        return $driversIds;
    }

    protected function isTimeValidated($args)
    {
        $occupiedPeriods = $this->model
            ->select('schedule_time','next_free_time')
            ->whereScheduled($args['user_id'])
            ->whereRaw('
                (? >= schedule_time AND ? < next_free_time) OR 
                (? >= schedule_time AND ? < next_free_time)
            ', [
                $args['schedule_time'], $args['schedule_time'], 
                $args['next_free_time'], $args['next_free_time']
            ])
            ->first();
        
        if($occupiedPeriods || time() > strtotime($args['schedule_time'])) {
            return false;
        }
        return true;
    }
}