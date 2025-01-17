<?php

// Simulates heat pump heat output and COP based on flow temperature, outside temperature and power consumption

function carnot_cop_simulator($dir,$p)
{
    $recalc = false;
        
    // Params ($p for process)
    if (!isset($p->condenser_offset)) return false;
    if (!isset($p->refrigerant_offset)) return false;
    if (!isset($p->practical_efficiency_factor)) return false;
    if (!isset($p->running_power_threshold)) return false;
    
    if ($p->practical_efficiency_factor>1.0) $p->practical_efficiency_factor = 1.0;
    if ($p->practical_efficiency_factor<0.0) $p->practical_efficiency_factor = 0.0;
    if ($p->running_power_threshold<0.0) $p->running_power_threshold = 0.0;
    
    $model = new ModelHelper($dir,$p);
    
    if (!$model->input('heatpump_elec')) return false;
    if (!$model->input('heatpump_flowT')) return false;
    if (!$model->input('heatpump_returnT')) return false;
    if (!$model->input('heatpump_outsideT')) return false;
    
    if (!$model->output('heatpump_cop_sim')) return false;
    if (!$model->output('heatpump_heat_sim')) return false;
    if (!$model->output('heatpump_heat_sim_kwh')) return false;
    if (!$model->output('heatpump_flowrate_sim')) return false;
       
    // Work out output data interval and start_time 
    $interval = $model->meta['heatpump_elec']->interval;
    
    $start_time = $model->start_time;
    $end_time = $model->end_time;
    
    // Note: implementation only allows for same meta for all output feeds
    $model->set_output_meta($start_time,$interval);
    
    // Simulation start time
    if (!$recalc) $start_time = $model->meta['heatpump_heat_sim']->end_time-$interval;
    
    if ($start_time==$end_time) {
        print "Nothing to do, data already up to date\n";
        return true;
    }
        
    $power = 0;
    $flowT = 0;
    $returnT = 0;
    $outsideT = 0;
    $heat_kwh = 0;

    // get cumulative kwh value
    $model->seek_to_time($start_time);
    if ($model->meta['heatpump_heat_sim']->npoints) {
        $heat_kwh = $model->read('heatpump_heat_sim_kwh',$heat_kwh);
    }
    // reset again
    $model->seek_to_time($start_time);
    
    $i=0;
    for ($time=$start_time; $time<$end_time; $time+=$interval) 
    {
        $model->seek_to_time_inputs($time);
        $power = $model->read('heatpump_elec',$power);
        $flowT = $model->read('heatpump_flowT',$flowT);
        $returnT = $model->read('heatpump_returnT',$returnT);
        $outsideT = $model->read('heatpump_outsideT',$outsideT);
        
        // Limits
        if ($power<0) $power = 0;
        
        $Tc = $flowT+$p->condenser_offset+273;
        $Tr = $outsideT+$p->refrigerant_offset+273;

        $heat = 0;
        $carnot = 0;

        if ($power>$p->running_power_threshold) {
            $dT = $Tc-$Tr;
            if ($dT>0.0) {
                $carnot = $p->practical_efficiency_factor*($Tc / $dT);
            }
            $heat = $power * $carnot;
            if ($returnT>$flowT) $heat *= -1;
        }
        
        $dT = $flowT - $returnT;
        
        $flow_rate = 0;
        if ($dT>0.5) {
            $flow_rate = ($heat / (4150*$dT))*3.6;
        }
        if ($flow_rate<0) $flow_rate = 0;
        
        $heat_kwh += ($heat * $interval)/3600000.0;
        
        $model->write('heatpump_cop_sim',$carnot);
        $model->write('heatpump_heat_sim',$heat);
        $model->write('heatpump_heat_sim_kwh',$heat_kwh);
        $model->write('heatpump_flowrate_sim',$flow_rate);
        
        $i++;
        if ($i%102400==0) echo ".";
    }
    echo "\n";
    
    $buffersize = $model->save_all();
    print "buffer size: ".($buffersize/1024)." kb\n";
    
    return true;
}
