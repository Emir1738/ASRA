package app.asra.client

import android.Manifest
import android.app.AlarmManager
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.job.JobInfo
import android.app.job.JobScheduler
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.PowerManager
import android.os.SystemClock

object AsraWatch {
    private const val BACKUP_MS = 120_000L
    private const val REPEAT_MS = 180_000L
    private const val REQ = 77
    private const val REQ_REPEAT = 82
    private const val JOB_ID = 91

    @Volatile
    var appVisible: Boolean = false

    @Volatile
    var serviceAlive: Boolean = false

    @Volatile
    private var lastScheduleAt = 0L

    @Volatile
    private var lastEngineAt = 0L

    @Volatile
    private var lastReadyAt = 0L

    @Volatile
    private var legacyCleared = false

    fun canNotify(context: Context): Boolean {
        if (Build.VERSION.SDK_INT >= 24) {
            try {
                val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
                if (!manager.areNotificationsEnabled()) return false
            } catch (_: Exception) {
            }
        }
        if (Build.VERSION.SDK_INT < 33) return true
        return context.checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) ==
            PackageManager.PERMISSION_GRANTED
    }

    fun shouldSuppressNotify(context: Context): Boolean {
        if (!appVisible) return false
        return try {
            val pm = context.getSystemService(Context.POWER_SERVICE) as PowerManager
            if (Build.VERSION.SDK_INT >= 20) pm.isInteractive else {
                @Suppress("DEPRECATION")
                pm.isScreenOn
            }
        } catch (_: Exception) {
            true
        }
    }

    fun sync(context: Context) {
        val app = context.applicationContext
        try {
            AsraSession.persist(app)
            if (AsraSession.shouldWatch(app)) {
                AsraSession.setWatch(app, true)
                startEngine(app)
                schedule(app)
                AsraNtfy.ensureRegistered(app)
            }
        } catch (_: Exception) {
        }
    }

    fun start(context: Context) {
        AsraSession.setWatch(context, true)
        sync(context)
    }

    fun refresh(context: Context) = sync(context)

    fun keep(context: Context) = sync(context)

    fun ensureEngine(context: Context) = startEngine(context)

    fun onNotificationsReady(context: Context) {
        val app = context.applicationContext
        val now = SystemClock.elapsedRealtime()
        if (now - lastReadyAt < 1_500L) return
        lastReadyAt = now
        AsraSession.persist(app)
        if (!AsraSession.shouldWatch(app)) return
        startEngine(app, force = true)
        schedule(app, force = true)
        AsraNtfy.ensureRegistered(app)
        Thread({
            try {
                AsraInbox.poll(app)
            } catch (_: Exception) {
            }
        }, "asra-ready-poll").start()
    }

    fun startEngine(context: Context, force: Boolean = false) {
        val app = context.applicationContext
        if (!AsraSession.shouldWatch(app)) return
        val now = SystemClock.elapsedRealtime()
        if (!force && serviceAlive && now - lastEngineAt < 4_000L) return
        lastEngineAt = now
        val intent = Intent(app, AsraNotifyService::class.java)
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                app.startForegroundService(intent)
            } else {
                app.startService(intent)
            }
        } catch (_: Exception) {
            try {
                app.startService(intent)
            } catch (_: Exception) {
                schedule(app, force = true)
            }
        }
    }

    fun schedule(context: Context, force: Boolean = false) {
        if (!AsraSession.shouldWatch(context)) return
        val app = context.applicationContext
        clearLegacy(app)
        val now = SystemClock.elapsedRealtime()
        if (!force && now - lastScheduleAt < 20_000L) return
        lastScheduleAt = now
        armBackup(app)
        armRepeating(app)
        scheduleJob(app)
    }

    fun scheduleRestart(context: Context) = schedule(context, force = true)

    fun scheduleSoon(context: Context) = schedule(context, force = true)

    fun scheduleJob(context: Context) {
        if (!AsraSession.shouldWatch(context)) return
        try {
            val app = context.applicationContext
            val scheduler = app.getSystemService(Context.JOB_SCHEDULER_SERVICE) as JobScheduler
            try {
                scheduler.cancel(92)
            } catch (_: Exception) {
            }
            val job = JobInfo.Builder(JOB_ID, ComponentName(app, AsraPingJob::class.java))
                .setRequiredNetworkType(JobInfo.NETWORK_TYPE_ANY)
                .setPersisted(true)
                .setPeriodic(15 * 60 * 1000L)
                .build()
            scheduler.schedule(job)
        } catch (_: Exception) {
        }
    }

    private fun clearLegacy(app: Context) {
        if (legacyCleared) return
        legacyCleared = true
        val alarm = app.getSystemService(Context.ALARM_SERVICE) as AlarmManager
        listOf(78, 79, 80, 81).forEach { code ->
            try {
                alarm.cancel(pendingIntent(app, code))
            } catch (_: Exception) {
            }
        }
    }

    private fun armBackup(app: Context) {
        val alarm = app.getSystemService(Context.ALARM_SERVICE) as AlarmManager
        val pending = pendingIntent(app, REQ)
        val elapsed = SystemClock.elapsedRealtime() + BACKUP_MS
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                val canExact = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                    try {
                        alarm.canScheduleExactAlarms()
                    } catch (_: Exception) {
                        false
                    }
                } else {
                    true
                }
                if (canExact) {
                    try {
                        alarm.setExactAndAllowWhileIdle(AlarmManager.ELAPSED_REALTIME_WAKEUP, elapsed, pending)
                        return
                    } catch (_: Exception) {
                    }
                }
                alarm.setAndAllowWhileIdle(AlarmManager.ELAPSED_REALTIME_WAKEUP, elapsed, pending)
            } else {
                @Suppress("DEPRECATION")
                alarm.set(AlarmManager.ELAPSED_REALTIME_WAKEUP, elapsed, pending)
            }
        } catch (_: Exception) {
        }
    }

    private fun armRepeating(app: Context) {
        try {
            val alarm = app.getSystemService(Context.ALARM_SERVICE) as AlarmManager
            val pending = pendingIntent(app, REQ_REPEAT)
            val start = SystemClock.elapsedRealtime() + REPEAT_MS
            @Suppress("DEPRECATION")
            alarm.setRepeating(AlarmManager.ELAPSED_REALTIME_WAKEUP, start, REPEAT_MS, pending)
        } catch (_: Exception) {
        }
    }

    private fun pendingIntent(context: Context, requestCode: Int): PendingIntent {
        val intent = Intent(context, AsraAlarmReceiver::class.java)
        intent.action = "app.asra.client.POLL"
        intent.setPackage(context.packageName)
        val flags = PendingIntent.FLAG_UPDATE_CURRENT or
            if (Build.VERSION.SDK_INT >= 23) PendingIntent.FLAG_IMMUTABLE else 0
        return PendingIntent.getBroadcast(context, requestCode, intent, flags)
    }
}
