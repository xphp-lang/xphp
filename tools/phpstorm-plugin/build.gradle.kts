// Build script for the xphp PhpStorm plugin.
//
// Uses the IntelliJ Platform Gradle Plugin 2.x DSL -- the successor to the
// legacy `gradle-intellij-plugin`.  The 2.x DSL is the supported path going
// forward and the one JetBrains' own plugin template uses.
//
// All version pins live in gradle.properties so a single edit there propagates
// to every coordinate (since-build, IDE target, kotlin runtime, jvm toolchain).
// Reach them with `providers.gradleProperty("...")` rather than
// `project.findProperty(...)` so Gradle's configuration cache stays happy.

import org.jetbrains.intellij.platform.gradle.IntelliJPlatformType
import org.jetbrains.intellij.platform.gradle.TestFrameworkType

plugins {
    id("java")
    id("org.jetbrains.kotlin.jvm") version "2.3.21"
    id("org.jetbrains.intellij.platform") version "2.16.0"
}

group = providers.gradleProperty("pluginGroup").get()
version = providers.gradleProperty("pluginVersion").get()

java {
    toolchain {
        languageVersion.set(JavaLanguageVersion.of(providers.gradleProperty("javaVersion").get().toInt()))
    }
}

kotlin {
    jvmToolchain(providers.gradleProperty("javaVersion").get().toInt())
}

// Repositories live here (not in settings.gradle.kts) because the settings-
// level `org.jetbrains.intellij.platform.settings` plugin can't expose its
// `intellijPlatform { defaultRepositories() }` Kotlin accessor reliably --
// see settings.gradle.kts for the longer rationale.  Applying the main
// plugin at the project level via `plugins {}` above gives us the accessor
// here without any of that grief.
repositories {
    mavenCentral()

    intellijPlatform {
        defaultRepositories()
    }
}

dependencies {
    intellijPlatform {
        // PhpStorm matches the LSP-API target window we picked in
        // gradle.properties.  `create(IntelliJPlatformType.PhpStorm, version)`
        // resolves the binary IDE distribution from JetBrains' installers repo
        // (the `jetbrainsIdeInstallers` entry registered by
        // `defaultRepositories()`), not from a Maven artifact -- there is no
        // Maven publication for the full PhpStorm distribution.
        create(
            IntelliJPlatformType.PhpStorm,
            providers.gradleProperty("platformVersion").get(),
        )

        // The bundled PHP plugin gives us the platform PHP language classes
        // (file type registry, PSI, PhpStorm-specific configurables).  Without
        // it our File-Type registration sits alongside PHP's instead of being
        // recognised as a sibling.
        bundledPlugins(providers.gradleProperty("platformBundledPlugins").map { it.split(",") })

        // Toolchain components used by the build / verify pipeline.
        pluginVerifier()
        zipSigner()

        // Test fixtures distributed with the IntelliJ Platform -- gives us
        // BasePlatformTestCase and friends for unit tests in chunk 3+.
        testFramework(TestFrameworkType.Platform)
    }

    testImplementation("org.junit.jupiter:junit-jupiter:5.11.4")
    testImplementation("org.opentest4j:opentest4j:1.3.0")
}

intellijPlatform {
    pluginConfiguration {
        version.set(providers.gradleProperty("pluginVersion"))

        ideaVersion {
            sinceBuild.set(providers.gradleProperty("pluginSinceBuild"))
            untilBuild.set(providers.gradleProperty("pluginUntilBuild"))
        }
    }

    pluginVerification {
        ides {
            // Verify against the same baseline we target.  Future minors get
            // added when they ship; the until-build window catches the rest.
            recommended()
        }
    }
}

tasks {
    test {
        useJUnitPlatform()
    }

    // Pre-empt the IntelliJ Platform Gradle Plugin's tendency to download a
    // brand-new JBR every clean -- pin to the bundled toolchain when CI runs.
    wrapper {
        gradleVersion = "9.0.0"
    }
}
