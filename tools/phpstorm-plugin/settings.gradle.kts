// Gradle settings file.
//
// `pluginManagement` MUST be the first block.  After that:
//   * `rootProject.name` is the canonical project identifier.
//   * Repositories deliberately live in `build.gradle.kts`, NOT in
//     `dependencyResolutionManagement {}` here.
//
// Why: the IntelliJ Platform Gradle Plugin's settings-level companion
// (`org.jetbrains.intellij.platform.settings`) provides an
// `intellijPlatform { defaultRepositories() }` DSL on top of
// `RepositoryHandler`, but the Kotlin DSL accessor doesn't get generated
// reliably -- the settings script compiles before its own `plugins {}`
// block applies, so `intellijPlatform` resolves to "unresolved reference".
// We get the same outcome (the `jetbrainsIdeInstallers` repo, the IntelliJ
// dependencies cache redirector, etc.) by applying the main plugin at the
// project level in build.gradle.kts where accessor generation works
// normally.

pluginManagement {
    repositories {
        gradlePluginPortal()
    }
}

rootProject.name = "xphp-phpstorm-plugin"

dependencyResolutionManagement {
    // PREFER_PROJECT: project-level `repositories {}` win.  Default in
    // Gradle 8 was relaxed; in Gradle 9 explicit mode declaration is the
    // safer pattern when we rely on project-side repo registration.
    repositoriesMode.set(RepositoriesMode.PREFER_PROJECT)
}
