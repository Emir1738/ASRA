package app.asra.client

import android.app.job.JobParameters
import android.app.job.JobService

class AsraPingJob : JobService() {
    override fun onStartJob(params: JobParameters?): Boolean {
        Thread({
            try {
                if (AsraSession.shouldWatch(this)) {
                    AsraWatch.startEngine(this, force = true)
                    // Push mimarisi: 30 dk'lık job hostu YOKLAMAZ (yalnız ntfy.sh)
                    AsraInbox.poll(this, ntfyOnly = true)
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
