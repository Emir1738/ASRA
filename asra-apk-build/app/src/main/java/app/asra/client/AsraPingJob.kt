package app.asra.client

import android.app.job.JobParameters
import android.app.job.JobService

class AsraPingJob : JobService() {
    override fun onStartJob(params: JobParameters?): Boolean {
        Thread({
            try {
                if (AsraSession.shouldWatch(this)) {
                    AsraWatch.startEngine(this, force = true)
                    AsraInbox.poll(this)
                    AsraWatch.schedule(this, force = true)
                }
            } catch (_: Exception) {
            } finally {
                jobFinished(params, false)
            }
        }, "asra-job").start()
        return true
    }

    override fun onStopJob(params: JobParameters?): Boolean = true
}
